<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Infrastructure\Backup\PostgresTools;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Failure paths only: they behave the same whether or not the client tools are installed.
 */
final class PostgresToolsTest extends TestCase
{
    private const string URL = 'postgresql://app:secret@db.example:5433/app?serverVersion=18&charset=utf8';

    public function testDescribesTheTargetWithoutCredentials(): void
    {
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::LOCAL, sys_get_temp_dir());

        self::assertSame('app_test on db.example:5433', $tools->target());
        self::assertStringNotContainsString('secret', $tools->target());
    }

    public function testRejectsAnUnknownMode(): void
    {
        $tools = new PostgresTools($this->connection(), self::URL, 'ssh', sys_get_temp_dir());

        try {
            $tools->mode();
            self::fail('An unknown mode should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('BACKUP_PG_TOOLS is "ssh".', $failure->getMessage());
            self::assertStringContainsString('"local"', (string) $failure->hint);
        }
    }

    public function testExplainsAToolThatCannotBeRun(): void
    {
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::LOCAL, sys_get_temp_dir());

        try {
            $tools->version('ivena-no-such-pg-tool');
            self::fail('A missing tool should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('ivena-no-such-pg-tool could not be run (local mode).', $failure->getMessage());
            self::assertStringContainsString('BACKUP_PG_TOOLS=docker', (string) $failure->hint);
        }
    }

    public function testAFailedDumpNamesTheToolButNotThePassword(): void
    {
        $fixture = new StorageFixtureDirectory();
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::LOCAL, $fixture->path);

        try {
            $tools->dump('not-a-snapshot', $fixture->path.'/database.dump');
            self::fail('The dump should fail.');
        } catch (BackupFailed $failure) {
            self::assertStringStartsWith('pg_dump failed with exit code', $failure->getMessage());
            self::assertStringNotContainsString('secret', $failure->getMessage());
        } finally {
            $fixture->remove();
        }
    }

    public function testReturnsTheVersionOutputOfATool(): void
    {
        // `true` ignores (BSD) or answers (GNU) --version and always exits 0, so the success path runs without
        // PostgreSQL client tools.
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::LOCAL, sys_get_temp_dir());

        self::assertIsString($tools->version('true'));
    }

    public function testExplainsTheDockerModeWhenTheServiceCannotBeReached(): void
    {
        $fixture = new StorageFixtureDirectory();
        // An empty directory has no compose file, so `docker compose` fails whether or not Docker is installed.
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::DOCKER, $fixture->path);

        try {
            $tools->version('pg_dump');
            self::fail('An unreachable Docker service should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('pg_dump could not be run (docker mode).', $failure->getMessage());
            self::assertStringContainsString('docker compose up -d database', (string) $failure->hint);
        } finally {
            $fixture->remove();
        }
    }

    public function testAFailedDockerDumpNamesTheToolButNotThePassword(): void
    {
        $fixture = new StorageFixtureDirectory();
        $tools = new PostgresTools($this->connection(), self::URL, PostgresTools::DOCKER, $fixture->path);

        try {
            $tools->dump('not-a-snapshot', $fixture->path.'/database.dump');
            self::fail('The dump should fail.');
        } catch (BackupFailed $failure) {
            self::assertStringStartsWith('pg_dump failed with exit code', $failure->getMessage());
            self::assertStringNotContainsString('secret', $failure->getMessage());
            self::assertFileDoesNotExist($fixture->path.'/database.dump');
        } finally {
            $fixture->remove();
        }
    }

    public function testExplainsAnUnreachableDatabase(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection refused'));
        $tools = new PostgresTools($connection, self::URL, PostgresTools::LOCAL, sys_get_temp_dir());

        try {
            $tools->target();
            self::fail('An unreachable database should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('The database is not reachable.', $failure->getMessage());
        }
    }

    private function connection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        // The name comes from the open connection, so a test suffix of the database name is respected.
        $connection->method('fetchOne')->willReturn('app_test');

        return $connection;
    }
}
