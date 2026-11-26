<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupCreator;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupSources;
use App\Storage\Application\Backup\BackupStep;
use App\Storage\Application\Backup\DatabaseDump;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Application\Contract\DatabaseDumperInterface;
use App\Storage\Application\Contract\FileArchiverInterface;
use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Layout\ReleaseDirectory;
use App\Storage\Application\Layout\StorageLayout;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class BackupCreatorTest extends TestCase
{
    private const string NAME = 'ivena-stats-full-20261120T103005Z';

    private StorageFixtureDirectory $fixture;

    private string $target;

    private LockFactory $lockFactory;

    private ?StorageLayout $layout = null;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->target = $this->fixture->path.'/backups';
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testWritesTheBackupUnderItsFinalNameWithManifestAndChecksums(): void
    {
        $steps = [];
        $creator = $this->creator($this->dumper(), $this->archiver());

        $manifest = $creator->create($creator->plan($this->target), static function (BackupStep $step, string $detail) use (&$steps): void {
            $steps[] = $step->value.('' === $detail ? '' : ': '.$detail);
        });

        $directory = $this->target.'/'.self::NAME;
        self::assertSame(self::NAME, $manifest->id);
        self::assertDirectoryExists($directory);
        self::assertDirectoryDoesNotExist($this->target.'/.'.self::NAME.'.partial');
        self::assertSame(0o700, fileperms($directory) & 0o777);
        self::assertSame(0o600, fileperms($directory.'/manifest.json') & 0o777);
        self::assertSame(0o600, fileperms($directory.'/database.dump') & 0o777);

        self::assertSame([
            'database.dump' => ['bytes' => 4, 'sha256' => hash('sha256', 'dump')],
            'files/media.sha256' => ['bytes' => 5, 'sha256' => hash('sha256', 'media')],
            'files/media.tar.gz' => ['bytes' => 3, 'sha256' => hash('sha256', 'tar')],
        ], $manifest->payloads);

        $data = json_decode((string) file_get_contents($directory.'/manifest.json'), true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame($manifest->toArray(), $data);
        self::assertSame('2026-11-20T10:30:05+00:00', $data['created_at']);
        self::assertSame(['version' => 'v0.3.0-test', 'environment' => 'test'], $data['application']);
        self::assertStringNotContainsString($this->fixture->path, (string) file_get_contents($directory.'/manifest.json'));

        self::assertSame([
            'preflight',
            'preflight: pg_dump 18',
            'database',
            'database: 2 tables, 5 rows',
            'files',
            'files: imports: 0 files, 0 bytes (directory does not exist)',
            'files: media: 1 files, 7 bytes, 1 symbolic link(s) skipped',
            'files: geo: 0 files, 0 bytes',
            'files: reference: 0 files, 0 bytes',
            'checksums',
            'checksums: 3 payload file(s)',
            'completion',
            'completion: '.$directory,
        ], $steps);
    }

    public function testAFailedRunRemovesOnlyItsOwnPartialDirectory(): void
    {
        $this->fixture->file('backups/ivena-stats-full-20261101T000000Z/manifest.json', 10);
        $creator = $this->creator($this->dumper(), $this->archiver(failOnMedia: true));

        try {
            $creator->create($creator->plan($this->target), static function (): void {});
            self::fail('The backup should have failed.');
        } catch (BackupFailed $failure) {
            self::assertSame(BackupStep::Files, $failure->step);
            self::assertSame('media cannot be read.', $failure->getMessage());
        }

        self::assertSame(['ivena-stats-full-20261101T000000Z'], array_values(array_diff((array) scandir($this->target), ['.', '..'])));
    }

    public function testAnUnexpectedErrorBecomesAFailureOfItsStep(): void
    {
        $creator = $this->creator($this->dumper(unexpected: true), $this->archiver());

        try {
            $creator->create($creator->plan($this->target), static function (): void {});
            self::fail('The backup should have failed.');
        } catch (BackupFailed $failure) {
            self::assertSame(BackupStep::Database, $failure->step);
            self::assertSame('Unexpected error: disk full', $failure->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $failure->getPrevious());
        }

        self::assertSame([], array_values(array_diff((array) scandir($this->target), ['.', '..'])));
    }

    public function testNeverOverwritesAnExistingBackup(): void
    {
        $this->fixture->file('backups/'.self::NAME.'/manifest.json', 10);
        $creator = $this->creator($this->dumper(), $this->archiver());

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('already exists');

        $creator->create($creator->plan($this->target), static function (): void {});
    }

    public function testRefusesWhileAnUnfinishedBackupIsInTheWay(): void
    {
        $this->fixture->directory('backups/.'.self::NAME.'.partial');
        $creator = $this->creator($this->dumper(), $this->archiver());

        try {
            $creator->create($creator->plan($this->target), static function (): void {});
            self::fail('The backup should have failed.');
        } catch (BackupFailed $failure) {
            self::assertSame(BackupStep::Preflight, $failure->step);
            self::assertStringContainsString('unfinished backup', $failure->getMessage());
        }

        self::assertDirectoryExists($this->target.'/.'.self::NAME.'.partial');
    }

    public function testRefusesWhileAnotherBackupOrRestoreHoldsTheLock(): void
    {
        $lock = $this->lockFactory->createLock(BackupCreator::LOCK);
        $lock->acquire();
        $creator = $this->creator($this->dumper(), $this->archiver());

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('Another backup or restore is running.');

        $creator->create($creator->plan($this->target), static function (): void {});
    }

    public function testRefusesATargetBelowThePublicDirectory(): void
    {
        $creator = $this->creator($this->dumper(), $this->archiver());

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('lies below public/');

        $creator->plan('public/backups');
    }

    public function testResolvesARelativeTargetAgainstTheProjectDirectory(): void
    {
        $plan = $this->creator($this->dumper(), $this->archiver())->plan('var/backups');

        self::assertSame($this->fixture->path.'/var/backups', $plan->targetDirectory);
        self::assertSame($this->fixture->path.'/var/backups/'.self::NAME, $plan->backupDirectory);
        self::assertSame('app on db:5432', $plan->database);
        self::assertSame([], $plan->warnings);
    }

    public function testWarnsWhenTheTargetLiesInsideADeployerRelease(): void
    {
        $this->layout = StorageLayout::deployer(
            $this->fixture->path,
            new ReleaseDirectory('42', $this->fixture->path.'/releases/42'),
            [],
            $this->fixture->path.'/shared',
            [],
        );

        $plan = $this->creator($this->dumper(), $this->archiver())->plan($this->fixture->path.'/releases/42/var/backups');

        self::assertCount(1, $plan->warnings);
        self::assertStringContainsString('inside a Deployer release', $plan->warnings[0]);
    }

    private function creator(DatabaseDumperInterface $dumper, FileArchiverInterface $archiver): BackupCreator
    {
        $layout = $this->layout ?? StorageLayout::standalone($this->fixture->path);

        return new BackupCreator(
            $dumper,
            $archiver,
            new BackupSources($this->fixture->path.'/imports', $this->fixture->path.'/media', $this->fixture->path.'/geo', $this->fixture->path.'/reference'),
            new readonly class($layout) implements StorageLayoutDetectorInterface {
                public function __construct(private StorageLayout $layout)
                {
                }

                public function detect(): StorageLayout
                {
                    return $this->layout;
                }
            },
            $this->lockFactory,
            new MockClock('2026-11-20 10:30:05 UTC'),
            $this->fixture->path,
            'v0.3.0-test',
            'test',
        );
    }

    private function dumper(bool $unexpected = false): DatabaseDumperInterface
    {
        return new readonly class($unexpected) implements DatabaseDumperInterface {
            public function __construct(private bool $unexpected)
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
                if ($this->unexpected) {
                    throw new \RuntimeException('disk full');
                }

                file_put_contents($targetFile, 'dump');

                return new DatabaseDump('18', 'pg_dump 18', ['DoctrineMigrations\Version1'], ['allocation' => 3, 'import' => 2]);
            }
        };
    }

    /**
     * Imports are missing, media has one file (and one skipped link), geo and reference are empty.
     */
    private function archiver(bool $failOnMedia = false): FileArchiverInterface
    {
        return new readonly class($failOnMedia) implements FileArchiverInterface {
            public function __construct(private bool $failOnMedia)
            {
            }

            public function preflight(): void
            {
            }

            public function archive(FileComponentSource $source, string $archiveFile, string $checksumFile): ArchivedFiles
            {
                if ('imports' === $source->component->value) {
                    return new ArchivedFiles(0, 0, 0, sourceMissing: true);
                }
                if ('media' !== $source->component->value) {
                    return new ArchivedFiles(0, 0, 0);
                }
                if ($this->failOnMedia) {
                    throw new BackupFailed('media cannot be read.');
                }

                file_put_contents($archiveFile, 'tar');
                file_put_contents($checksumFile, 'media');

                return new ArchivedFiles(1, 7, 1);
            }
        };
    }
}
