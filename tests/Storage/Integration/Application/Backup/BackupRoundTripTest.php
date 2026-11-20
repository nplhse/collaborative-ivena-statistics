<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Application\Backup;

use App\Storage\Application\Backup\BackupCreator;
use App\Storage\Application\Backup\BackupRestorer;
use App\Storage\Application\Backup\BackupSources;
use App\Storage\Application\Backup\BackupVerifier;
use App\Storage\Application\Backup\DatabaseDump;
use App\Storage\Application\Contract\DatabaseDumperInterface;
use App\Storage\Application\Contract\MigrationCatalogInterface;
use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Infrastructure\Backup\TarArchiveExtractor;
use App\Storage\Infrastructure\Backup\TarArchiveReader;
use App\Storage\Infrastructure\Backup\TarFileArchiver;
use App\Tests\Support\Storage\InMemoryDatabaseRestorer;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use App\Tests\Support\Storage\StubBackupReaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Create, verify and restore with the real file tools (system tar, the PHP archive reader): files changed after
 * the backup come back exactly as they were. The database side is in memory; PostgresDatabaseRestorerTest and the
 * restore drill in backup-restore.md cover PostgreSQL.
 */
final class BackupRoundTripTest extends TestCase
{
    public const string MIGRATION = 'DoctrineMigrations\Version20261119220659';

    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testRestoresTheFilesAsTheyWereWhenTheBackupWasMade(): void
    {
        $app = $this->fixture->path.'/app';
        $this->fixture->file('app/imports/2026/hospital-1.csv', 120);
        $this->fixture->file('app/imports/.hidden', 1);
        $this->fixture->file('app/imports/pending/7/upload.bin', 9);
        $this->fixture->file('app/media/logo.png', 2048);
        $this->fixture->file('app/reference/catalog.json', 64);
        $sources = new BackupSources($app.'/imports', $app.'/media', $app.'/geo', $app.'/reference');
        $lockFactory = new LockFactory(new InMemoryStore());
        $quiet = static function (): void {
        };

        $creator = new BackupCreator(
            $this->dumper(),
            new TarFileArchiver($this->fixture->path),
            $sources,
            new readonly class(StorageLayout::standalone($this->fixture->path)) implements StorageLayoutDetectorInterface {
                public function __construct(private StorageLayout $layout)
                {
                }

                public function detect(): StorageLayout
                {
                    return $this->layout;
                }
            },
            $lockFactory,
            new MockClock('2026-11-20 03:00:00 UTC'),
            $this->fixture->path,
            'v0.3.0-test',
            'test',
        );
        $manifest = $creator->create($creator->plan($this->fixture->path.'/backups'), $quiet);
        $backup = $this->fixture->path.'/backups/'.$manifest->id;

        $verifier = new BackupVerifier(StubBackupReaders::dumpReader(['allocation']), new TarArchiveReader());
        self::assertTrue($verifier->verify($backup, $quiet)->isValid());

        // The application keeps working after the backup.
        unlink($app.'/imports/2026/hospital-1.csv');
        file_put_contents($app.'/media/logo.png', 'changed');
        $this->fixture->file('app/media/new.png', 5);

        $restorer = new BackupRestorer(
            $verifier,
            new InMemoryDatabaseRestorer(['allocation' => 9], ['allocation' => 4]),
            new TarArchiveExtractor(),
            new readonly class implements MigrationCatalogInterface {
                public function available(): array
                {
                    return [BackupRoundTripTest::MIGRATION];
                }
            },
            $sources,
            $lockFactory,
            new MockClock('2026-11-21 08:00:00 UTC'),
        );
        $result = $restorer->restore($restorer->plan($backup), $quiet);

        self::assertSame(['.hidden', '2026/hospital-1.csv'], $this->files($app.'/imports'), 'Pending uploads are not part of a backup.');
        self::assertSame(['logo.png'], $this->files($app.'/media'));
        self::assertSame(str_repeat('x', 2048), file_get_contents($app.'/media/logo.png'));
        self::assertSame(['catalog.json'], $this->files($app.'/reference'));

        self::assertSame([], $result->previousDirectories, 'The replaced directories are removed after the switch.');
        self::assertSame([], glob($app.'/{,.}*{restore}*', \GLOB_BRACE));
        self::assertDirectoryDoesNotExist($app.'/geo', 'geo did not exist and is not part of the backup.');
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $files[] = substr($file->getPathname(), \strlen($directory) + 1);
        }
        sort($files);

        return $files;
    }

    private function dumper(): DatabaseDumperInterface
    {
        return new readonly class implements DatabaseDumperInterface {
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
                file_put_contents($targetFile, 'dump');

                return new DatabaseDump('18', 'pg_dump 18', [BackupRoundTripTest::MIGRATION], ['allocation' => 4]);
            }
        };
    }
}
