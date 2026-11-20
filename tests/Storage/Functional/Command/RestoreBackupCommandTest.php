<?php

declare(strict_types=1);

namespace App\Tests\Storage\Functional\Command;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupSources;
use App\Storage\Application\Contract\ArchiveExtractorInterface;
use App\Storage\Application\Contract\ArchiveReaderInterface;
use App\Storage\Application\Contract\DatabaseRestorerInterface;
use App\Storage\Application\Contract\DumpReaderInterface;
use App\Storage\Application\Contract\MigrationCatalogInterface;
use App\Tests\Support\Storage\BackupDirectoryBuilder;
use App\Tests\Support\Storage\InMemoryDatabaseRestorer;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use App\Tests\Support\Storage\StubBackupReaders;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command wiring with an in-memory database and placeholder archives; BackupRestorerTest covers the flow.
 */
final class RestoreBackupCommandTest extends KernelTestCase
{
    private StorageFixtureDirectory $fixture;

    private string $backup;

    private InMemoryDatabaseRestorer $database;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->backup = BackupDirectoryBuilder::write($this->fixture->directory('backups'));
        $this->database = new InMemoryDatabaseRestorer();
        $this->fixture->file('app/media/current.txt', 3);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testShowsThePlanAndChangesNothingWithoutConfirmation(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute(['backup' => $this->backup]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('app on db:5432: replaced by 2 tables with 6 rows', $display);
        self::assertStringContainsString($this->fixture->path.'/app/media: replaced by 2 files', $display);
        self::assertStringContainsString('Nothing was changed.', $display);
        self::assertSame([], $this->database->calls);
    }

    public function testRefusesAnUnattendedRestoreWithoutTheFlag(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $this->backup], ['interactive' => false]));

        self::assertStringContainsString('Unattended restores need --confirm-destructive.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame([], $this->database->calls);
    }

    public function testRestoresAndListsWhatIsLeftToDo(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['backup' => $this->backup, '--confirm-destructive' => true], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('[OK] Backup '.BackupDirectoryBuilder::NAME.' restored.', $display);
        self::assertStringContainsString('The current database is dropped before the backup is restored', $display);
        self::assertStringContainsString('✔ Removed '.$this->fixture->path.'/app/media.pre-restore-', $display);
        self::assertStringNotContainsString('doctrine:migrations:migrate', $display);
        self::assertFileExists($this->fixture->path.'/app/media/a.txt');
    }

    public function testReportsTheFailureAndWhatItLeftBehind(): void
    {
        $this->database->restored = ['allocation' => 1];
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $this->backup, '--confirm-destructive' => true], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Restore failed during "Database restore": The restored database differs from the manifest', $display);
        self::assertStringContainsString('The current database was dropped and the backup is not (completely) restored', $display);
    }

    public function testKeepsThePreviousStateWhenAsked(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['backup' => $this->backup, '--confirm-destructive' => true, '--keep-previous' => true], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('✔ Previous database schema removed', $display);
        self::assertStringContainsString('Remove the replaced directories once the application works: '.$this->fixture->path.'/app/media.pre-restore-', $display);
    }

    public function testRefusesABackupOfANewerApplication(): void
    {
        $tester = $this->tester(available: ['DoctrineMigrations\Version20260101000000']);

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $this->backup, '--confirm-destructive' => true], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('this application does not know', $display);
        self::assertStringContainsString('Nothing was changed.', $display);
    }

    public function testShowsThePlanWarningsAndTheUnderlyingErrorWhenVeryVerbose(): void
    {
        $this->database->restored = ['allocation' => 1];
        $tester = $this->tester(available: ['DoctrineMigrations\Version20260101000000', 'DoctrineMigrations\Version20261119220659', 'DoctrineMigrations\Version20261201000000']);

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $this->backup, '--confirm-destructive' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] The backup is 1 migration(s) behind this application.', $display);
        self::assertStringContainsString(BackupFailed::class, $display);
    }

    /**
     * @param list<string> $available
     */
    private function tester(array $available = ['DoctrineMigrations\Version20260101000000', 'DoctrineMigrations\Version20261119220659']): CommandTester
    {
        self::bootKernel();
        $container = self::getContainer();
        $app = $this->fixture->path.'/app';

        $container->set(DatabaseRestorerInterface::class, $this->database);
        $container->set(ArchiveExtractorInterface::class, StubBackupReaders::extractor());
        $container->set(DumpReaderInterface::class, StubBackupReaders::dumpReader());
        $container->set(ArchiveReaderInterface::class, StubBackupReaders::archiveReader());
        $container->set(BackupSources::class, new BackupSources($app.'/imports', $app.'/media', $app.'/geo', $app.'/reference'));
        $container->set(MigrationCatalogInterface::class, new readonly class($available) implements MigrationCatalogInterface {
            /**
             * @param list<string> $available
             */
            public function __construct(private array $available)
            {
            }

            public function available(): array
            {
                return $this->available;
            }
        });

        return new CommandTester(new Application(self::$kernel)->find('app:backup:restore'));
    }
}
