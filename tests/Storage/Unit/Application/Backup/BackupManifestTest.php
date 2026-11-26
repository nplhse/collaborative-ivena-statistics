<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupComponent;
use App\Storage\Application\Backup\BackupFormat;
use App\Storage\Application\Backup\BackupManifest;
use App\Storage\Application\Backup\DatabaseDump;
use PHPUnit\Framework\TestCase;

final class BackupManifestTest extends TestCase
{
    public function testNamesTheDirectoryWithTypeAndUtcTimestamp(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-20 11:30:05', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('ivena-stats-full-20261120T103005Z', BackupFormat::directoryName('full', $createdAt));
        self::assertSame('.ivena-stats-full-20261120T103005Z.partial', BackupFormat::partialDirectoryName('ivena-stats-full-20261120T103005Z'));
        self::assertSame('files/media.tar.gz', BackupFormat::archivePath(BackupComponent::Media));
        self::assertSame('files/media.sha256', BackupFormat::checksumListPath(BackupComponent::Media));
    }

    public function testListsTheFileComponentsWithoutTheDatabase(): void
    {
        self::assertSame(
            [BackupComponent::Imports, BackupComponent::Media, BackupComponent::Geo, BackupComponent::Reference],
            BackupComponent::fileComponents(),
        );
    }

    public function testTheLatestMigrationIsTheLastOneInOrder(): void
    {
        self::assertNull(new DatabaseDump('18', 'pg_dump 18', [], [])->latestMigration());
        self::assertSame('B\Version2', new DatabaseDump('18', 'pg_dump 18', ['A\Version1', 'B\Version2'], [])->latestMigration());
    }

    public function testDescribesTheBackupWithCountsAndChecksumsOnly(): void
    {
        $manifest = new BackupManifest(
            id: 'ivena-stats-full-20261120T103005Z',
            type: 'full',
            createdAt: new \DateTimeImmutable('2026-11-20 11:30:05', new \DateTimeZone('Europe/Berlin')),
            appVersion: 'v0.3.0-beta',
            environment: 'prod',
            database: new DatabaseDump('18.4', 'pg_dump (PostgreSQL) 18.4', ['DoctrineMigrations\Version20250101000000'], ['allocation' => 3, 'import' => 1]),
            files: [
                'imports' => new ArchivedFiles(2, 300, 0),
                'media' => new ArchivedFiles(0, 0, 1),
                'geo' => new ArchivedFiles(0, 0, 0, sourceMissing: true),
            ],
            payloads: ['database.dump' => ['bytes' => 10, 'sha256' => str_repeat('a', 64)]],
        );

        $data = $manifest->toArray();

        self::assertSame('ivena-stats-backup', $data['format']);
        self::assertSame(1, $data['format_version']);
        self::assertSame('2026-11-20T10:30:05+00:00', $data['created_at']);
        self::assertSame(['version' => 'v0.3.0-beta', 'environment' => 'prod'], $data['application']);
        self::assertSame([
            'server_version' => '18.4',
            'dump_tool_version' => 'pg_dump (PostgreSQL) 18.4',
            'latest_migration' => 'DoctrineMigrations\Version20250101000000',
            'executed_migrations' => ['DoctrineMigrations\Version20250101000000'],
            'table_rows' => ['allocation' => 3, 'import' => 1],
        ], $data['database']);
        self::assertSame([
            'database' => ['payloads' => ['database.dump']],
            'imports' => ['files' => 2, 'bytes' => 300, 'skipped_links' => 0, 'source_missing' => false, 'payloads' => ['files/imports.tar.gz', 'files/imports.sha256']],
            'media' => ['files' => 0, 'bytes' => 0, 'skipped_links' => 1, 'source_missing' => false, 'payloads' => []],
            'geo' => ['files' => 0, 'bytes' => 0, 'skipped_links' => 0, 'source_missing' => true, 'payloads' => []],
        ], $data['components']);

        self::assertStringEndsWith("\n", $manifest->toJson());
        self::assertSame($data, json_decode($manifest->toJson(), true, 16, \JSON_THROW_ON_ERROR));
    }
}
