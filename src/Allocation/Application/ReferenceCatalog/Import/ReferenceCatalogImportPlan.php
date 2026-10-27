<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;

/**
 * What an additive import would do: rows and aliases to create, rows that already exist, and conflicts.
 *
 * One conflict stops the import before anything is written.
 */
final readonly class ReferenceCatalogImportPlan
{
    /**
     * @param list<PlannedRow>                             $rows      in import order
     * @param list<PlannedAlias>                           $aliases
     * @param array<string, array{create: int, skip: int}> $counts    keyed by type, aliases as "<type>-alias"
     * @param list<ReferenceCatalogViolation>              $conflicts
     */
    public function __construct(
        public array $rows,
        public array $aliases,
        public array $counts,
        public array $conflicts,
    ) {
    }

    public function hasConflicts(): bool
    {
        return [] !== $this->conflicts;
    }

    public function createCount(): int
    {
        return \count($this->rows) + \count($this->aliases);
    }
}
