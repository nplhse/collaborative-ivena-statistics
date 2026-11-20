<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Infrastructure\Backup\PostgresDumpReader;
use App\Storage\Infrastructure\Backup\PostgresTools;
use PHPUnit\Framework\TestCase;

/**
 * Parses `pg_restore --list` output; pg_restore itself is replaced, because CI has no client tools that match the
 * server version.
 */
final class PostgresDumpReaderTest extends TestCase
{
    public function testListsTheTablesWithData(): void
    {
        $toc = <<<'TOC'
            ;
            ; Archive created at 2026-11-20 18:38:24 UTC
            ;     Format: CUSTOM
            ;
            218; 1259 13775998 TABLE public user app
            4973; 0 13776023 TABLE DATA public user app
            4985; 0 13776150 TABLE DATA public allocation app
            5012; 0 13776889 TABLE DATA public allocation_stats_projection app
            6001; 0 13779999 MATERIALIZED VIEW DATA public mv_allocation_daily app
            ;5000; 0 13779998 TABLE DATA public commented_out app
            TOC;

        $tools = $this->createMock(PostgresTools::class);
        $tools->expects(self::once())->method('listDump')->with('/backups/x/database.dump')->willReturn($toc);
        $tools->method('version')->with('pg_restore')->willReturn('pg_restore (PostgreSQL) 18.4');

        $reader = new PostgresDumpReader($tools);

        self::assertSame(['allocation', 'allocation_stats_projection', 'user'], $reader->tables('/backups/x/database.dump'));
        self::assertSame('pg_restore (PostgreSQL) 18.4', $reader->preflight());
    }
}
