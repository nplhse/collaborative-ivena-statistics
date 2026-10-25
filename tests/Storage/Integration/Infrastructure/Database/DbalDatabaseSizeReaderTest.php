<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Database;

use App\Storage\Infrastructure\Database\DbalDatabaseSizeReader;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class DbalDatabaseSizeReaderTest extends DatabaseKernelTestCase
{
    public function testReadsThePostgresDatabaseSize(): void
    {
        self::bootKernel();

        $bytes = new DbalDatabaseSizeReader(self::getContainer()->get(Connection::class))->bytes();

        self::assertGreaterThan(1024 * 1024, $bytes);
    }

    public function testRejectsAMissingSize(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not report a database size');

        new DbalDatabaseSizeReader($connection)->bytes();
    }

    public function testOtherPlatformsAreNotSupported(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only supported on PostgreSQL');

        new DbalDatabaseSizeReader($connection)->bytes();
    }
}
