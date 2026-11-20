<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Infrastructure\Backup\PostgresDatabaseDumper;
use App\Storage\Infrastructure\Backup\PostgresTools;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * The snapshot part against the test database; pg_dump itself is replaced, because CI has no client tools that
 * match the server version.
 *
 * The test connection is wrapped in a transaction (DAMA), so the dumper gets a connection of its own with the
 * same parameters: it sees the migrated schema, not the rows of this test.
 */
final class PostgresDatabaseDumperTest extends DatabaseKernelTestCase
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

    public function testReadsCountsAndMigrationsFromTheExportedSnapshot(): void
    {
        $connection = $this->ownConnection();
        $snapshots = [];
        $tools = $this->createMock(PostgresTools::class);
        $tools->method('version')->with('pg_dump')->willReturn('pg_dump (PostgreSQL) 18.4');
        $tools->expects(self::once())->method('dump')->willReturnCallback(static function (string $snapshot, string $file) use (&$snapshots, $connection): void {
            self::assertTrue($connection->isTransactionActive(), 'pg_dump runs while the snapshot transaction is open.');
            $snapshots[] = $snapshot;
            file_put_contents($file, 'dump');
        });

        $dump = new PostgresDatabaseDumper($connection, $tools)->dump($this->fixture->path.'/database.dump');

        self::assertCount(1, $snapshots);
        self::assertMatchesRegularExpression('/^[0-9A-F]+-[0-9A-F]+-\d+$/', $snapshots[0]);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame('pg_dump (PostgreSQL) 18.4', $dump->dumpToolVersion);
        self::assertNotSame('', $dump->serverVersion);

        self::assertArrayHasKey('allocation', $dump->tableRows);
        self::assertArrayNotHasKey('spatial_ref_sys', $dump->tableRows);
        $tables = array_keys($dump->tableRows);
        $sorted = $tables;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $tables);

        $migrations = (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
        self::assertSame($migrations, $dump->tableRows['doctrine_migration_versions']);
        self::assertCount($migrations, $dump->executedMigrations);
        $timestamps = array_map(static fn (string $version): string => (string) preg_replace('/^.*Version(\d+).*$/', '$1', $version), $dump->executedMigrations);
        $ordered = $timestamps;
        sort($ordered, \SORT_STRING);
        self::assertSame($ordered, $timestamps);
        self::assertSame($dump->executedMigrations[$migrations - 1], $dump->latestMigration());
    }

    public function testFailsWhenTheDumpToolWritesNothing(): void
    {
        $connection = $this->ownConnection();
        $tools = $this->createStub(PostgresTools::class);
        $tools->method('version')->willReturn('pg_dump 18');
        $tools->method('dump')->willReturnCallback(static function (string $snapshot, string $file): void {
            touch($file);
        });

        try {
            new PostgresDatabaseDumper($connection, $tools)->dump($this->fixture->path.'/database.dump');
            self::fail('An empty dump should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('pg_dump wrote no data.', $failure->getMessage());
        }

        self::assertFalse($connection->isTransactionActive());
    }

    public function testClosesTheSnapshotWhenTheDumpToolFails(): void
    {
        $connection = $this->ownConnection();
        $tools = $this->createStub(PostgresTools::class);
        $tools->method('version')->willReturn('pg_dump 18');
        $tools->method('dump')->willThrowException(new BackupFailed('pg_dump failed with exit code 1: boom'));

        try {
            new PostgresDatabaseDumper($connection, $tools)->dump($this->fixture->path.'/database.dump');
            self::fail('The dump should fail.');
        } catch (BackupFailed $failure) {
            self::assertStringContainsString('boom', $failure->getMessage());
        }

        self::assertFalse($connection->isTransactionActive());
    }

    public function testRefusesAConnectionWithAnOpenTransaction(): void
    {
        $connection = $this->ownConnection();
        $connection->beginTransaction();

        try {
            new PostgresDatabaseDumper($connection, $this->createStub(PostgresTools::class))->dump($this->fixture->path.'/database.dump');
            self::fail('An open transaction should be refused.');
        } catch (BackupFailed $failure) {
            self::assertStringContainsString('already open', $failure->getMessage());
        } finally {
            $connection->rollBack();
        }
    }

    public function testPreflightReturnsTheToolVersion(): void
    {
        $tools = $this->createStub(PostgresTools::class);
        $tools->method('version')->willReturn('pg_dump 18');
        $tools->method('target')->willReturn('app on db:5432');

        $dumper = new PostgresDatabaseDumper($this->ownConnection(), $tools);

        self::assertSame('pg_dump 18', $dumper->preflight());
        self::assertSame('app on db:5432', $dumper->target());
    }

    private function ownConnection(): Connection
    {
        self::bootKernel();
        $params = self::getContainer()->get(Connection::class)->getParams();

        return DriverManager::getConnection($params);
    }
}
