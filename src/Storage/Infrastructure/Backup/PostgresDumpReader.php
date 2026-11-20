<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Contract\DumpReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads the table of contents of a custom-format dump with `pg_restore --list` (ADR 038, "Verify").
 *
 * pg_restore reads the whole archive structure, so a truncated or damaged dump fails here. No database
 * connection is needed for the listing.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for DumpReaderInterface.
 */
#[AsAlias(DumpReaderInterface::class)]
final readonly class PostgresDumpReader implements DumpReaderInterface
{
    /** A table data entry: "<id>; <oid> <oid> TABLE DATA public <table> <owner>". */
    private const string TABLE_DATA = '/^\d+; \d+ \d+ TABLE DATA public (\S+) \S+$/m';

    public function __construct(
        private PostgresTools $tools,
    ) {
    }

    #[\Override]
    public function preflight(): string
    {
        return $this->tools->version('pg_restore');
    }

    #[\Override]
    public function tables(string $dumpFile): array
    {
        preg_match_all(self::TABLE_DATA, $this->tools->listDump($dumpFile), $matches);

        $tables = array_values(array_unique($matches[1]));
        sort($tables);

        return $tables;
    }
}
