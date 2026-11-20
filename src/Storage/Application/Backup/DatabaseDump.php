<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * What a database dump contains, read from the same snapshot as the dump itself (ADR 038, "Consistency").
 *
 * The executed migrations are kept in full, so a restore can tell an older backup (a subset of the migrations
 * the code knows) from a newer one (migrations the code does not know).
 */
final readonly class DatabaseDump
{
    /**
     * @param list<string>       $executedMigrations in migration order (timestamp in the class name)
     * @param array<string, int> $tableRows          rows per table in schema public, sorted by table name
     */
    public function __construct(
        public string $serverVersion,
        public string $dumpToolVersion,
        public array $executedMigrations,
        public array $tableRows,
    ) {
    }

    public function latestMigration(): ?string
    {
        return [] === $this->executedMigrations ? null : $this->executedMigrations[array_key_last($this->executedMigrations)];
    }
}
