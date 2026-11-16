<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * The effective assignment of encounters to IVENA allocations (#1300, ADR 037): `encounter.allocation_id`, at
 * most one encounter per allocation. It is the authoritative source; the case file, the assignment service and
 * the check whether an allocation is taken read it there. A schema that keeps its own matching history (the ED
 * matching in `encounter_match`) writes that history and calls this service in the same transaction.
 *
 * Call inside a transaction that holds the encounter hospital lock (EncounterHospitalLockInterface).
 */
interface EncounterAllocationAssignmentsInterface
{
    /**
     * Sets the given encounters to the given allocations (null removes the assignment). An encounter whose
     * assignment is unchanged keeps its time and author. An allocation that another encounter holds is taken
     * away from that encounter first: callers decide this deliberately (a reviewer's decision, or a matcher that
     * treats taken allocations as reserved). The updates run in an order that keeps the uniqueness at every
     * statement, so swapping two allocations works in one call.
     *
     * @param array<int, int|null> $allocationByEncounter allocation id (or null) by encounter id
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); the ED matching calls it.
     */
    public function apply(array $allocationByEncounter, ?int $userId): void;

    /**
     * Removes the assignments to the allocations of an IVENA import that is being deleted or reprocessed, before
     * its rows go (the foreign key would do the same at the end), so that work in the same transaction (a
     * matcher rerun) already sees those encounters free. The encounters and their clinical records stay.
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); the ED matching calls it.
     */
    public function releaseAllocationsOfImport(int $importId): void;

    /**
     * The encounter of an IVENA allocation for a record that a schema assigned uniquely to that allocation:
     * the one the allocation is assigned to, or a new encounter from $base that is assigned to it in the same
     * step (#1300, ADR 037). At most one encounter per allocation, also with parallel callers. The caller
     * attaches its record in the same transaction, under the encounter hospital lock.
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function encounterForAllocation(int $allocationId, EncounterBaseData $base): int;

    /**
     * The encounter the allocation is assigned to, or null.
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function encounterOfAllocation(int $allocationId): ?int;
}
