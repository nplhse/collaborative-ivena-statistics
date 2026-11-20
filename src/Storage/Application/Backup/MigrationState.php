<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * How the migration state of a backup relates to this application (ADR 038, "Restore").
 *
 * A backup may be older (it lacks migrations the code knows: restore, then migrate) but not newer (it holds
 * migrations the code does not know: its schema cannot be described by this code, so the restore is refused).
 */
final readonly class MigrationState
{
    /**
     * @param list<string> $pending migrations the code knows but the backup has not executed
     * @param list<string> $unknown migrations the backup has executed but the code does not know
     */
    public function __construct(
        public array $pending,
        public array $unknown,
    ) {
    }

    /**
     * @param list<string> $executed  migrations executed in the backup
     * @param list<string> $available migrations of this application
     */
    public static function compare(array $executed, array $available): self
    {
        return new self(
            array_values(array_diff($available, $executed)),
            array_values(array_diff($executed, $available)),
        );
    }

    public function isNewer(): bool
    {
        return [] !== $this->unknown;
    }

    public function isOlder(): bool
    {
        return [] !== $this->pending;
    }
}
