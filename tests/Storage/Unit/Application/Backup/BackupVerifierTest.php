<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupVerification;
use App\Storage\Application\Backup\BackupVerifier;
use App\Storage\Application\Backup\VerificationStep;
use App\Storage\Application\Contract\ArchiveReaderInterface;
use App\Storage\Application\Contract\DumpReaderInterface;
use App\Tests\Support\Storage\BackupDirectoryBuilder;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The checks of BackupVerifier with stand-in readers; TarArchiveReader and PostgresDumpReader have tests of
 * their own.
 */
final class BackupVerifierTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    private string $backup;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->backup = BackupDirectoryBuilder::write($this->fixture->path);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testAcceptsAnIntactBackupAndReportsEveryStep(): void
    {
        $lines = [];
        $verification = $this->verifier()->verify($this->backup, static function (VerificationStep $step, ?string $detail, bool $ok) use (&$lines): void {
            $lines[] = sprintf('%s %s %s', $step->value, $ok ? 'ok' : 'fail', $detail ?? '-');
        });

        self::assertTrue($verification->isValid());
        self::assertSame([], $verification->warnings);
        self::assertSame([
            'manifest ok -',
            'manifest ok Known format, full backup of 2026-11-20 10:30:05 UTC (application v0.3.0-beta)',
            'payloads ok -',
            'payloads ok database.dump (4 B)',
            'payloads ok files/media.sha256 (146 B)', // two lines: 64 hex + 2 spaces + path + newline
            'payloads ok files/media.tar.gz (7 B)',
            'database ok -',
            'database ok 2 tables readable with pg_restore 18; latest migration DoctrineMigrations\Version20261119220659',
            'files ok -',
            'files ok imports: no files',
            'files ok media: 2 files (10 B) match their checksums',
            'files ok geo: no files',
            'files ok reference: no files',
        ], $lines);
    }

    public function testADamagedDumpSkipsTheDumpCheckButNotTheFiles(): void
    {
        file_put_contents($this->backup.'/database.dump', 'dumX');

        $verification = $this->verify(dumpTables: static fn (): never => throw new \LogicException('A damaged dump must not be read.'));

        self::assertSame(['database.dump does not match its SHA-256 checksum.'], $verification->problems);
    }

    public function testReportsAMissingPayloadAndAFileThatDoesNotBelong(): void
    {
        unlink($this->backup.'/files/media.sha256');
        file_put_contents($this->backup.'/notes.txt', 'mine');

        $verification = $this->verify();

        self::assertSame(['files/media.sha256 is missing.'], $verification->problems);
        self::assertSame(['notes.txt is not part of the backup and is ignored.'], $verification->warnings);
    }

    public function testReportsTablesWithoutDataInTheDump(): void
    {
        $verification = $this->verify(dumpTables: static fn (): array => ['allocation']);

        self::assertSame(['The dump has no data for 1 tables of the manifest: hospital.'], $verification->problems);
    }

    public function testReportsAnUnreadableDump(): void
    {
        $verification = $this->verify(dumpTables: static fn (): never => throw new BackupFailed('pg_restore failed with exit code 1: input file is too short'));

        self::assertSame(['pg_restore failed with exit code 1: input file is too short'], $verification->problems);
    }

    public function testReportsArchiveEntriesThatDifferFromTheChecksumList(): void
    {
        $entries = BackupDirectoryBuilder::entries(['a.txt' => 'alpha', 'b/c.txt' => 'other', 'd.txt' => 'delta']);

        $verification = $this->verify(archiveEntries: static fn (): array => $entries);

        self::assertSame(['media: 0 files missing from the archive, 1 not in the checksum list, 1 with a wrong checksum: d.txt, b/c.txt.'], $verification->problems);
    }

    public function testReportsAnArchiveThatHoldsLessThanTheManifestRecords(): void
    {
        BackupDirectoryBuilder::editManifest($this->backup, static function (array $data): array {
            $data['components'] = ['media' => ['files' => 3, 'bytes' => 10, 'skipped_links' => 0, 'source_missing' => false, 'payloads' => []]] + (array) $data['components'];

            return $data;
        });

        $verification = $this->verify();

        self::assertSame(['media: the archive holds 2 files with 10 bytes; the manifest records 3 files with 10 bytes.'], $verification->problems);
    }

    public function testReportsAnUnsafeArchive(): void
    {
        $verification = $this->verify(archiveEntries: static fn (): never => throw new BackupFailed('The archive contains the unsafe path "../x".'));

        self::assertSame(['media: The archive contains the unsafe path "../x".'], $verification->problems);
    }

    public function testRefusesAnArchiveWhoseComponentListsNoPayload(): void
    {
        BackupDirectoryBuilder::editManifest($this->backup, static function (array $data): array {
            $payloads = (array) $data['payloads'];
            unset($payloads['files/media.tar.gz']);
            $data['payloads'] = $payloads;

            return $data;
        });

        $verification = $this->verify();

        self::assertContains('The manifest records 2 media files but lists no files/media.tar.gz.', $verification->problems);
        self::assertContains('files/media.tar.gz is not part of the backup and is ignored.', $verification->warnings);
    }

    public function testReportsAnInvalidChecksumList(): void
    {
        file_put_contents($this->backup.'/files/media.sha256', "not a checksum\n");
        BackupDirectoryBuilder::editManifest($this->backup, function (array $data): array {
            $data['payloads'] = ['files/media.sha256' => ['bytes' => 15, 'sha256' => hash_file('sha256', $this->backup.'/files/media.sha256')]] + (array) $data['payloads'];

            return $data;
        });

        self::assertSame(['media: Line 1 of files/media.sha256 is invalid.'], $this->verify()->problems);
    }

    public function testReportsAWrongSizeAndAPayloadTheFormatDoesNotKnow(): void
    {
        file_put_contents($this->backup.'/database.dump', 'longer dump');
        BackupDirectoryBuilder::editManifest($this->backup, static function (array $data): array {
            $data['payloads'] = ['../outside' => ['bytes' => 1, 'sha256' => str_repeat('0', 64)]] + (array) $data['payloads'];

            return $data;
        });

        self::assertSame([
            'The manifest lists the unexpected payload ../outside.',
            'database.dump has 11 bytes; the manifest records 4.',
        ], $this->verify()->problems);
    }

    public function testWarnsWhenTheDirectoryWasRenamed(): void
    {
        $renamed = $this->fixture->path.'/copy-of-backup';
        rename($this->backup, $renamed);
        $this->backup = $renamed;

        $verification = $this->verify();

        self::assertTrue($verification->isValid());
        self::assertSame(['The directory is named copy-of-backup, the backup id is '.BackupDirectoryBuilder::NAME.'.'], $verification->warnings);
    }

    public function testFailsWithoutTheRestoreTool(): void
    {
        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('pg_restore could not be run (local mode).');

        $this->verify(preflight: static fn (): never => throw new BackupFailed('pg_restore could not be run (local mode).'));
    }

    /**
     * @param (\Closure(): string)|null                                          $preflight
     * @param (\Closure(): list<string>)|null                                    $dumpTables
     * @param (\Closure(): array<string, array{size: int, sha256: string}>)|null $archiveEntries
     */
    private function verify(?\Closure $preflight = null, ?\Closure $dumpTables = null, ?\Closure $archiveEntries = null): BackupVerification
    {
        return $this->verifier($preflight, $dumpTables, $archiveEntries)->verify($this->backup, static function (): void {
        });
    }

    /**
     * @param (\Closure(): string)|null                                          $preflight
     * @param (\Closure(): list<string>)|null                                    $dumpTables
     * @param (\Closure(): array<string, array{size: int, sha256: string}>)|null $archiveEntries
     */
    private function verifier(?\Closure $preflight = null, ?\Closure $dumpTables = null, ?\Closure $archiveEntries = null): BackupVerifier
    {
        $dumpReader = new readonly class($preflight ?? static fn (): string => 'pg_restore 18', $dumpTables ?? static fn (): array => ['allocation', 'hospital', 'migrations']) implements DumpReaderInterface {
            public function __construct(private \Closure $preflight, private \Closure $tables)
            {
            }

            public function preflight(): string
            {
                return ($this->preflight)();
            }

            public function tables(string $dumpFile): array
            {
                return ($this->tables)();
            }
        };

        $archiveReader = new readonly class($archiveEntries ?? static fn (): array => BackupDirectoryBuilder::entries()) implements ArchiveReaderInterface {
            public function __construct(private \Closure $entries)
            {
            }

            public function entries(string $archiveFile): array
            {
                return ($this->entries)();
            }
        };

        return new BackupVerifier($dumpReader, $archiveReader);
    }
}
