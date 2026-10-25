<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Database;

use App\Storage\Application\Contract\DatabaseTableSizeReaderInterface;
use App\Storage\Application\DTO\DatabaseTableSize;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * pg_total_relation_size() of the user tables, largest first. Reads the catalog only, no table data.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for DatabaseTableSizeReaderInterface.
 */
#[AsAlias(DatabaseTableSizeReaderInterface::class)]
final readonly class DbalDatabaseTableSizeReader implements DatabaseTableSizeReaderInterface
{
    private const string SQL = <<<'SQL'
        SELECT n.nspname AS table_schema, c.relname AS table_name, pg_total_relation_size(c.oid) AS bytes
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind IN ('r', 'p', 'm')
          AND n.nspname NOT IN ('pg_catalog', 'information_schema')
          AND n.nspname NOT LIKE 'pg_toast%'
        ORDER BY bytes DESC, table_name ASC
        LIMIT :limit
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function largestTables(int $limit): array
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('Table sizes are only supported on PostgreSQL.');
        }

        $rows = $this->connection->fetchAllAssociative(self::SQL, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map(static function (array $row): DatabaseTableSize {
            if (!\is_string($row['table_schema'] ?? null) || !\is_string($row['table_name'] ?? null) || !is_numeric($row['bytes'] ?? null)) {
                throw new \RuntimeException('PostgreSQL returned a malformed table size.');
            }

            return new DatabaseTableSize($row['table_schema'], $row['table_name'], (int) $row['bytes']);
        }, $rows);
    }
}
