<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFormat;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Counts the rows of every table in schema public except the PostGIS tables and the runtime tables, whose rows are
 * not backed up (ADR 038, "Manifest"; {@see BackupFormat::RUNTIME_TABLES}).
 *
 * Used for the manifest of a backup and for the check after a restore, so both count the same tables.
 */
final class PublicTableRows
{
    /**
     * @return array<string, int> rows by table name, sorted by name
     */
    public static function count(Connection $connection): array
    {
        /** @var list<string> $tables */
        $tables = $connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN (?) ORDER BY table_name",
            [[...PostgresTools::POSTGIS_TABLES, ...BackupFormat::RUNTIME_TABLES]],
            [ArrayParameterType::STRING],
        );

        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM public.%s', $connection->quoteSingleIdentifier($table)));
        }

        return $rows;
    }
}
