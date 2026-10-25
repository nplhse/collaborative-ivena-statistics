<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Database;

use App\Storage\Application\DTO\DatabaseTableSize;
use App\Storage\Infrastructure\Database\DbalDatabaseTableSizeReader;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class DbalDatabaseTableSizeReaderTest extends DatabaseKernelTestCase
{
    public function testReadsTheLargestTablesFirst(): void
    {
        self::bootKernel();

        $tables = new DbalDatabaseTableSizeReader(self::getContainer()->get(Connection::class))->largestTables(5);

        self::assertCount(5, $tables);
        $sizes = array_map(static fn (DatabaseTableSize $table): int => $table->bytes, $tables);
        $sorted = $sizes;
        rsort($sorted);
        self::assertSame($sorted, $sizes);
        self::assertNotContains('pg_catalog', array_map(static fn (DatabaseTableSize $table): string => $table->schema, $tables));
    }

    public function testRejectsAMalformedRow(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchAllAssociative')->willReturn([['table_schema' => 'public', 'table_name' => 'import', 'bytes' => null]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed table size');

        new DbalDatabaseTableSizeReader($connection)->largestTables(10);
    }

    public function testOtherPlatformsAreNotSupported(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only supported on PostgreSQL');

        new DbalDatabaseTableSizeReader($connection)->largestTables(10);
    }
}
