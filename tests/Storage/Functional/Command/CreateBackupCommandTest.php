<?php

declare(strict_types=1);

namespace App\Tests\Storage\Functional\Command;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\DatabaseDump;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Application\Contract\DatabaseDumperInterface;
use App\Storage\Application\Contract\FileArchiverInterface;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command wiring with replaced dump and archive tools; the tools have tests of their own.
 */
final class CreateBackupCommandTest extends KernelTestCase
{
    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testCreatesABackupUnattended(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--target-dir' => $this->fixture->path], ['interactive' => false]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Create backup', $display);
        self::assertStringContainsString('app on db:5432', $display);
        self::assertStringContainsString('(without pending)', $display);
        self::assertStringContainsString('Database dump', $display);
        self::assertStringContainsString('1 tables, 4 rows', $display);
        self::assertStringContainsString('[OK] Backup ivena-stats-full-', $display);

        $backups = glob($this->fixture->path.'/ivena-stats-full-*/manifest.json');
        self::assertIsArray($backups);
        self::assertCount(1, $backups);
    }

    public function testAsksBeforeItStartsAndWritesNothingOnNo(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--target-dir' => $this->fixture->path]));

        self::assertStringContainsString('Nothing was written.', $tester->getDisplay());
        self::assertSame([], glob($this->fixture->path.'/ivena-stats-*'));
    }

    public function testReportsAFailureWithItsStepAndHint(): void
    {
        $tester = $this->tester(failingDump: true);

        self::assertSame(Command::FAILURE, $tester->execute(['--target-dir' => $this->fixture->path], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Backup failed during "Database dump": pg_dump failed with exit code 1', $display);
        self::assertStringContainsString('Check the server.', $display);
        self::assertStringContainsString('No backup was created.', $display);
        self::assertSame([], glob($this->fixture->path.'/{,.}ivena-stats-*', \GLOB_BRACE));
    }

    public function testShowsTheUnderlyingErrorWhenVeryVerbose(): void
    {
        $tester = $this->tester(failingDump: true);

        self::assertSame(Command::FAILURE, $tester->execute(['--target-dir' => $this->fixture->path], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE]));

        self::assertStringContainsString(BackupFailed::class, $tester->getDisplay());
    }

    public function testRefusesATargetBelowPublic(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--target-dir' => 'public/backups'], ['interactive' => false]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('lies below public/', $display);
        self::assertStringNotContainsString('No backup was created.', $display);
    }

    private function tester(bool $failingDump = false): CommandTester
    {
        self::bootKernel();

        self::getContainer()->set(DatabaseDumperInterface::class, new readonly class($failingDump) implements DatabaseDumperInterface {
            public function __construct(private bool $failing)
            {
            }

            public function target(): string
            {
                return 'app on db:5432';
            }

            public function preflight(): string
            {
                return 'pg_dump 18';
            }

            public function dump(string $targetFile): DatabaseDump
            {
                if ($this->failing) {
                    throw new BackupFailed('pg_dump failed with exit code 1: boom', 'Check the server.');
                }

                file_put_contents($targetFile, 'dump');

                return new DatabaseDump('18', 'pg_dump 18', ['DoctrineMigrations\Version1'], ['allocation' => 4]);
            }
        });
        self::getContainer()->set(FileArchiverInterface::class, new readonly class implements FileArchiverInterface {
            public function preflight(): void
            {
            }

            public function archive(FileComponentSource $source, string $archiveFile, string $checksumFile): ArchivedFiles
            {
                return new ArchivedFiles(0, 0, 0);
            }
        });

        return new CommandTester(new Application(self::$kernel)->find('app:backup:create'));
    }
}
