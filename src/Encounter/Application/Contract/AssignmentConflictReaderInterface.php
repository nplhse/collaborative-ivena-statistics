<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Assignment\AssignmentConflict;

/**
 * Open assignment conflicts for the read-only review page (ADR 037, #1274).
 */
interface AssignmentConflictReaderInterface
{
    /**
     * Hospitals with at least one open conflict, by name (for administrators, who hold REVIEW everywhere).
     *
     * @return list<array{id: int, name: string}>
     */
    public function hospitalsWithConflicts(): array;

    public function countOf(int $hospitalId): int;

    /**
     * Newest first.
     *
     * @return list<AssignmentConflict>
     */
    public function conflictsOf(int $hospitalId, int $limit): array;
}
