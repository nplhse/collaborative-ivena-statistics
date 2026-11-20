<?php

declare(strict_types=1);

namespace App\Tests\Storage\Functional\Command;

use App\Storage\Application\Contract\ArchiveReaderInterface;
use App\Storage\Application\Contract\DumpReaderInterface;
use App\Tests\Support\Storage\BackupDirectoryBuilder;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command wiring with replaced readers; the readers and the checks have tests of their own.
 */
final class VerifyBackupCommandTest extends KernelTestCase
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

    public function testConfirmsAnIntactBackup(): void
    {
        $backup = BackupDirectoryBuilder::write($this->fixture->path);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['backup' => $backup]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Payload checksums', $display);
        self::assertStringContainsString('✔ media: 2 files (10 B) match their checksums', $display);
        self::assertStringContainsString('[OK] Backup '.BackupDirectoryBuilder::NAME.' is complete and readable.', $display);
    }

    public function testShowsWarningsButStillConfirmsTheBackup(): void
    {
        $backup = BackupDirectoryBuilder::write($this->fixture->path);
        file_put_contents($backup.'/notes.txt', 'mine');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['backup' => $backup]));

        self::assertStringContainsString('[WARNING] notes.txt is not part of the backup and is ignored.', $tester->getDisplay());
    }

    public function testListsEveryProblemAndFails(): void
    {
        $backup = BackupDirectoryBuilder::write($this->fixture->path);
        file_put_contents($backup.'/database.dump', 'dumX');
        unlink($backup.'/files/media.sha256');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $backup]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('has 2 problems and must not be used for a restore', $display);
        self::assertStringContainsString('- database.dump does not match its SHA-256 checksum.', $display);
        self::assertStringContainsString('- files/media.sha256 is missing.', $display);
        self::assertStringContainsString('Skipped: database.dump is missing or damaged.', $display);
    }

    public function testExplainsAMissingBackup(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['backup' => $this->fixture->path.'/nothing']));

        self::assertStringContainsString('The backup cannot be verified: The backup', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function tester(): CommandTester
    {
        self::bootKernel();

        self::getContainer()->set(DumpReaderInterface::class, new readonly class implements DumpReaderInterface {
            public function preflight(): string
            {
                return 'pg_restore 18';
            }

            public function tables(string $dumpFile): array
            {
                return ['allocation', 'hospital'];
            }
        });
        self::getContainer()->set(ArchiveReaderInterface::class, new readonly class implements ArchiveReaderInterface {
            public function entries(string $archiveFile): array
            {
                return BackupDirectoryBuilder::entries();
            }
        });

        return new CommandTester(new Application(self::$kernel)->find('app:backup:verify'));
    }
}
