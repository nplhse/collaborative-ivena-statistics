<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Assignment\AssignmentConflict;
use App\Encounter\Application\Assignment\DeferredAssignment;

/**
 * Open assignment conflicts and deferred records for the conflict page (ADR 037, #1274, #1307).
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

    public function countDeferredOf(int $hospitalId): int;

    /**
     * Records left unassigned for now, latest decision first.
     *
     * @return list<DeferredAssignment>
     */
    public function deferredOf(int $hospitalId, int $limit): array;
}
