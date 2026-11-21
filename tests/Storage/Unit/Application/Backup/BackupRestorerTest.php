<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\BackupCreator;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupRestorer;
use App\Storage\Application\Backup\BackupSources;
use App\Storage\Application\Backup\BackupVerifier;
use App\Storage\Application\Backup\RestoreStep;
use App\Storage\Application\Contract\ArchiveExtractorInterface;
use App\Storage\Application\Contract\MigrationCatalogInterface;
use App\Tests\Support\Storage\BackupDirectoryBuilder;
use App\Tests\Support\Storage\InMemoryDatabaseRestorer;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use App\Tests\Support\Storage\StubBackupReaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The restore flow with an in-memory database and placeholder archives; the Postgres and tar parts have tests
 * of their own, BackupRoundTripTest runs the real file tools end to end.
 */
final class BackupRestorerTest extends TestCase
{
    private const string MIGRATION_1 = 'DoctrineMigrations\Version20260101000000';

    private const string MIGRATION_2 = 'DoctrineMigrations\Version20261119220659';

    private const string SUFFIX = '20261121T080000Z';

    private StorageFixtureDirectory $fixture;

    private string $backup;

    private InMemoryDatabaseRestorer $database;

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->backup = BackupDirectoryBuilder::write($this->fixture->directory('backups'));
        $this->database = new InMemoryDatabaseRestorer();
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->fixture->file('app/media/current.txt', 3);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReplacesDatabaseAndFilesAndRemovesWhatItReplaced(): void
    {
        $restorer = $this->restorer();

        $result = $restorer->restore($restorer->plan($this->backup), $this->quiet());

        self::assertSame(['preflight', 'clear', 'restore database.dump'], $this->database->calls, 'The current schema is dropped first: no second copy.');

        $media = $this->fixture->path.'/app/media';
        self::assertSame('alpha', file_get_contents($media.'/a.txt'));
        self::assertSame('gamma', file_get_contents($media.'/b/c.txt'));
        self::assertFileDoesNotExist($media.'/current.txt');
        self::assertDirectoryDoesNotExist($media.'.pre-restore-'.self::SUFFIX);
        self::assertSame([], $result->previousDirectories);
        self::assertSame([], glob($this->fixture->path.'/app/{,.}*{restore}*', \GLOB_BRACE));
    }

    public function testKeepsThePreviousStateUntilTheEndWhenAsked(): void
    {
        $steps = [];
        $restorer = $this->restorer();

        $result = $restorer->restore($restorer->plan($this->backup, keepPrevious: true), static function (RestoreStep $step, ?string $detail, bool $ok) use (&$steps): void {
            if (null === $detail) {
                $steps[] = $step->value;
            }
        });

        self::assertSame(['preflight', 'stash '.strtolower(self::SUFFIX), 'restore database.dump', 'discard pre_restore_'.strtolower(self::SUFFIX)], $this->database->calls);
        self::assertSame(['preflight', 'verification', 'files', 'database', 'switch', 'completion'], $steps);

        $media = $this->fixture->path.'/app/media';
        self::assertSame('alpha', file_get_contents($media.'/a.txt'));
        self::assertSame('gamma', file_get_contents($media.'/b/c.txt'));
        self::assertFileDoesNotExist($media.'/current.txt');
        self::assertFileExists($media.'.pre-restore-'.self::SUFFIX.'/current.txt');
        self::assertSame([$media.'.pre-restore-'.self::SUFFIX], $result->previousDirectories);

        // Components without files are restored as empty directories; nothing is left over from staging.
        self::assertDirectoryExists($this->fixture->path.'/app/imports');
        self::assertSame([], glob($this->fixture->path.'/app/.*.restore-*'));
        self::assertSame(0, $result->pendingMigrations);
    }

    public function testRefusesABackupWithMigrationsThisApplicationDoesNotKnow(): void
    {
        try {
            $this->restorer(available: [self::MIGRATION_1])->plan($this->backup);
            self::fail('A newer backup must be refused.');
        } catch (BackupFailed $failure) {
            self::assertSame('The backup holds 1 migration(s) this application does not know, e.g. '.self::MIGRATION_2.'.', $failure->getMessage());
            self::assertStringContainsString('v0.3.0-beta', (string) $failure->hint);
            self::assertSame(RestoreStep::Preflight, $failure->step);
        }
    }

    public function testAcceptsAnOlderBackupAndAsksForTheMigrations(): void
    {
        $restorer = $this->restorer(available: [self::MIGRATION_1, self::MIGRATION_2, 'DoctrineMigrations\Version20261201000000']);
        $plan = $restorer->plan($this->backup);

        self::assertSame(['DoctrineMigrations\Version20261201000000'], $plan->migrations->pending);
        self::assertStringContainsString('1 migration(s) behind', $plan->warnings[0]);
        self::assertSame(1, $restorer->restore($plan, $this->quiet())->pendingMigrations);
    }

    public function testLeavesComponentsAloneThatWereMissingInTheBackup(): void
    {
        BackupDirectoryBuilder::editManifest($this->backup, static function (array $data): array {
            $data['components'] = ['geo' => ['files' => 0, 'bytes' => 0, 'skipped_links' => 0, 'source_missing' => true, 'payloads' => []]] + (array) $data['components'];

            return $data;
        });
        $this->fixture->file('app/geo/kept.json', 2);

        $restorer = $this->restorer();
        $plan = $restorer->plan($this->backup);
        $restorer->restore($plan, $this->quiet());

        self::assertSame(['imports', 'media', 'reference'], array_map(static fn (\App\Storage\Application\Backup\RestoreTarget $target): string => $target->component->value, $plan->targets));
        self::assertStringContainsString('The backup has no geo directory', $plan->warnings[0]);
        self::assertFileExists($this->fixture->path.'/app/geo/kept.json');
    }

    public function testRestoresIntoTheRealDirectoryBehindALink(): void
    {
        $this->fixture->file('shared/reference/old.json', 2);
        $this->fixture->link('app/reference', $this->fixture->path.'/shared/reference');

        $restorer = $this->restorer();
        $restorer->restore($restorer->plan($this->backup), $this->quiet());

        self::assertTrue(is_link($this->fixture->path.'/app/reference'), 'The link of the release stays a link.');
        self::assertDirectoryExists($this->fixture->path.'/shared/reference');
        self::assertFileDoesNotExist($this->fixture->path.'/shared/reference/old.json');
        self::assertDirectoryDoesNotExist($this->fixture->path.'/shared/reference.pre-restore-'.self::SUFFIX);
    }

    public function testStopsBeforeAnyChangeWhenTheBackupHasAProblem(): void
    {
        file_put_contents($this->backup.'/database.dump', 'dumX');

        $failure = $this->failure();

        self::assertSame(RestoreStep::Verification, $failure->step);
        self::assertStringContainsString('database.dump does not match its SHA-256 checksum.', $failure->getMessage());
        self::assertSame('The database and the files were not changed.', $failure->state);
        self::assertSame(['preflight'], $this->database->calls);
        $this->assertFilesUnchanged();
    }

    public function testAcceptsAnOlderManifestThatCountsTheLockTable(): void
    {
        $this->backup = BackupDirectoryBuilder::write($this->fixture->directory('older'), tableRows: ['allocation' => 4, 'hospital' => 2, 'lock_keys' => 1]);
        // An older dump still holds the rows of the lock table.
        $restorer = $this->restorer(verifier: new BackupVerifier(StubBackupReaders::dumpReader(['allocation', 'hospital', 'lock_keys']), StubBackupReaders::archiveReader()));

        $restorer->restore($restorer->plan($this->backup), $this->quiet());

        self::assertSame(['preflight', 'clear', 'restore database.dump'], $this->database->calls, 'the lock row is not restored, so its count is not checked (#1452)');
    }

    public function testSaysTheDatabaseIsGoneWhenTheRestoreFailsAfterDroppingIt(): void
    {
        $this->database->restored = ['allocation' => 3, 'hospital' => 2];

        $failure = $this->failure();

        self::assertSame(RestoreStep::Database, $failure->step);
        self::assertSame('The restored database differs from the manifest: allocation (3 instead of 4).', $failure->getMessage());
        self::assertStringStartsWith('The files were not changed. The current database was dropped', (string) $failure->state);
        self::assertNotContains('rollback', $this->database->calls);
        $this->assertFilesUnchanged();
    }

    public function testRollsTheDatabaseBackWhenTheRowsDoNotMatch(): void
    {
        $this->database->restored = ['allocation' => 3, 'hospital' => 2];

        $failure = $this->failure(keepPrevious: true);

        self::assertSame(RestoreStep::Database, $failure->step);
        self::assertSame('The restored database differs from the manifest: allocation (3 instead of 4).', $failure->getMessage());
        self::assertSame('The database and the files were not changed.', $failure->state);
        self::assertSame('rollback pre_restore_'.strtolower(self::SUFFIX), $this->database->calls[array_key_last($this->database->calls)]);
        $this->assertFilesUnchanged();
    }

    public function testNamesTheKeptSchemaWhenTheRollbackFails(): void
    {
        $this->database->onRestore = static fn (): never => throw new BackupFailed('pg_restore failed with exit code 1: out of disk space');
        $this->database->failRollback = true;

        $failure = $this->failure(keepPrevious: true);

        self::assertSame('pg_restore failed with exit code 1: out of disk space', $failure->getMessage());
        self::assertStringContainsString('the previous schema is kept as pre_restore_'.strtolower(self::SUFFIX), (string) $failure->state);
        $this->assertFilesUnchanged();
    }

    public function testRefusesFilesThatDoNotMatchAfterExtraction(): void
    {
        $failure = $this->failure(extractor: StubBackupReaders::extractor(['a.txt' => 'alpha', 'b/c.txt' => 'other']));

        self::assertSame(RestoreStep::Files, $failure->step);
        self::assertSame('media/b/c.txt does not match its checksum after extraction.', $failure->getMessage());
        self::assertSame(['preflight'], $this->database->calls);
        $this->assertFilesUnchanged();
    }

    public function testSwitchesEverythingBackWhenADirectoryCannotBeReplaced(): void
    {
        $blocker = $this->fixture->path.'/app/media.pre-restore-'.self::SUFFIX;
        // Appears after the preflight: media cannot be moved aside, after imports was already switched.
        $this->database->onRestore = static function () use ($blocker): void {
            mkdir($blocker);
            touch($blocker.'/in-the-way');
        };

        $failure = $this->failure(keepPrevious: true);
        unlink($blocker.'/in-the-way');
        rmdir($blocker);

        self::assertSame(RestoreStep::Switch, $failure->step);
        self::assertStringContainsString('could not be renamed', $failure->getMessage());
        self::assertSame('The database and the files were not changed.', $failure->state);
        self::assertSame('rollback pre_restore_'.strtolower(self::SUFFIX), $this->database->calls[array_key_last($this->database->calls)]);
        $this->assertFilesUnchanged();
    }

    public function testSwitchesTheFilesBackButKeepsTheRestoredDatabaseByDefault(): void
    {
        $blocker = $this->fixture->path.'/app/media.pre-restore-'.self::SUFFIX;
        $this->database->onRestore = static function () use ($blocker): void {
            mkdir($blocker);
            touch($blocker.'/in-the-way');
        };

        $failure = $this->failure();
        unlink($blocker.'/in-the-way');
        rmdir($blocker);

        self::assertSame(RestoreStep::Switch, $failure->step);
        self::assertSame('The files were not changed; the database already holds the backup. Fix the cause and restore again, so files and database match.', $failure->state);
        $this->assertFilesUnchanged();
    }

    public function testRefusesABackupWithoutAReadableManifest(): void
    {
        unlink($this->backup.'/manifest.json');

        try {
            $this->restorer()->plan($this->backup);
            self::fail('A backup without manifest cannot be restored.');
        } catch (BackupFailed $failure) {
            self::assertSame(RestoreStep::Preflight, $failure->step);
            self::assertStringContainsString('has no manifest.json', $failure->getMessage());
        }
    }

    public function testWrapsAnUnexpectedError(): void
    {
        $extractor = new readonly class implements ArchiveExtractorInterface {
            public function extract(string $archiveFile, string $targetDirectory): void
            {
                throw new \RuntimeException('disk gone');
            }
        };

        $failure = $this->failure($extractor);

        self::assertSame(RestoreStep::Files, $failure->step);
        self::assertSame('Unexpected error: disk gone', $failure->getMessage());
        $this->assertFilesUnchanged();
    }

    public function testRefusesFilesTheChecksumListDoesNotName(): void
    {
        $failure = $this->failure(extractor: StubBackupReaders::extractor(['a.txt' => 'alpha', 'b/c.txt' => 'gamma', 'extra.txt' => 'x']));

        self::assertSame('media: 3 files were extracted, the backup holds 2.', $failure->getMessage());
        $this->assertFilesUnchanged();
    }

    public function testRefusesWhenARestoreDirectoryIsInTheWay(): void
    {
        mkdir($this->fixture->path.'/app/media.pre-restore-'.self::SUFFIX);

        $failure = $this->failure();

        self::assertSame(RestoreStep::Preflight, $failure->step);
        self::assertSame('A restore directory for media already exists.', $failure->getMessage());
        rmdir($this->fixture->path.'/app/media.pre-restore-'.self::SUFFIX);
        $this->assertFilesUnchanged();
    }

    public function testWarnsWhenThePreviousSchemaCannotBeDropped(): void
    {
        $this->database->failDiscard = true;
        $restorer = $this->restorer();

        $result = $restorer->restore($restorer->plan($this->backup, keepPrevious: true), $this->quiet());

        self::assertSame(['The previous database schema pre_restore_'.strtolower(self::SUFFIX).' could not be removed: permission denied. Drop it by hand.'], $result->warnings);
        self::assertFileExists($this->fixture->path.'/app/media/a.txt');
    }

    public function testRefusesWhileAnotherBackupOrRestoreRuns(): void
    {
        $lock = $this->lockFactory->createLock(BackupCreator::LOCK);
        $lock->acquire();

        $failure = $this->failure();

        self::assertSame('Another backup or restore is running.', $failure->getMessage());
        self::assertSame('Nothing was changed.', $failure->state);
        self::assertSame([], $this->database->calls);
    }

    private function assertFilesUnchanged(): void
    {
        self::assertFileExists($this->fixture->path.'/app/media/current.txt');
        self::assertFileDoesNotExist($this->fixture->path.'/app/media/a.txt');
        self::assertDirectoryDoesNotExist($this->fixture->path.'/app/imports');
        self::assertSame([], glob($this->fixture->path.'/app/{,.}*{restore}*', \GLOB_BRACE));
    }

    private function failure(?ArchiveExtractorInterface $extractor = null, bool $keepPrevious = false): BackupFailed
    {
        $restorer = $this->restorer(extractor: $extractor);

        try {
            $restorer->restore($restorer->plan($this->backup, $keepPrevious), $this->quiet());
        } catch (BackupFailed $failure) {
            return $failure;
        }

        self::fail('The restore should fail.');
    }

    /**
     * @return \Closure(RestoreStep, ?string, bool): void
     */
    private function quiet(): \Closure
    {
        return static function (): void {
        };
    }

    /**
     * @param list<string> $available
     */
    private function restorer(array $available = [self::MIGRATION_1, self::MIGRATION_2], ?ArchiveExtractorInterface $extractor = null, ?BackupVerifier $verifier = null): BackupRestorer
    {
        $app = $this->fixture->path.'/app';

        return new BackupRestorer(
            $verifier ?? StubBackupReaders::verifier(),
            $this->database,
            $extractor ?? StubBackupReaders::extractor(),
            new readonly class($available) implements MigrationCatalogInterface {
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
            },
            new BackupSources($app.'/imports', $app.'/media', $app.'/geo', $app.'/reference'),
            $this->lockFactory,
            new MockClock('2026-11-21 08:00:00 UTC'),
        );
    }
}
