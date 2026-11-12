<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Read model of the match review (ADR 029, #1054): counts with denominators and the review lists of one
 * hospital and period.
 */
interface MatchReviewReaderInterface
{
    /**
     * Day of the latest visit of the hospital, to open the review on a period with data.
     */
    public function latestVisitDay(int $hospitalId): ?\DateTimeImmutable;

    /**
     * Hospitals with encounter data, for administrators who hold REVIEW implicitly.
     *
     * @return list<array{id: int, name: string}>
     */
    public function hospitalsWithEncounters(): array;

    /**
     * @return array{
     *     allocations: int, automatic: int, confirmed: int, proposed: int, conflicts: int, no_proposal: int, rejected: int,
     *     visits: array<string, array{total: int, linked: int, unlinked: int}>, visits_total: int, visits_linked: int
     * }
     */
    public function summary(int $hospitalId, ReviewPeriod $period): array;

    /**
     * Pairs of one status list (proposed, conflicts, automatic, confirmed, rejected), by IVENA arrival.
     *
     * @return list<array<string, mixed>>
     */
    public function pairs(int $hospitalId, ReviewPeriod $period, ReviewFilter $filter, int $offset, int $limit): array;

    public function countPairs(int $hospitalId, ReviewPeriod $period, ReviewFilter $filter): int;

    /**
     * Allocations without link and without proposal, by IVENA arrival.
     *
     * @return list<array{allocation_id: int, arrival_at: \DateTimeImmutable, gender: string, age: int, with_physician: bool, work_accident: bool}>
     */
    public function allocationsWithoutProposal(int $hospitalId, ReviewPeriod $period, int $offset, int $limit): array;

    public function countAllocationsWithoutProposal(int $hospitalId, ReviewPeriod $period): int;

    /**
     * Visits without effective link, by matching anchor; with the allocation of their best open proposal.
     *
     * @return list<array{encounter_id: int, anchor_at: \DateTimeImmutable, substitute: bool, sex: string, age: int, arrival_route: string, arrival_mode: ?string, proposal_allocation_id: ?int}>
     */
    public function unlinkedVisits(int $hospitalId, ReviewPeriod $period, ?ArrivalRoute $route, int $offset, int $limit): array;

    public function countUnlinkedVisits(int $hospitalId, ReviewPeriod $period, ?ArrivalRoute $route): int;

    /**
     * The allocation of the review page, or null when it does not belong to the hospital.
     *
     * @return array{id: int, public_id: string, arrival_at: \DateTimeImmutable, gender: string, age: int, with_physician: bool, work_accident: bool}|null
     */
    public function allocation(int $hospitalId, int $allocationId): ?array;

    /**
     * Stored rows touching the given visits: the allocation's own pairs and the visits' links elsewhere.
     *
     * @param list<int> $encounterIds
     *
     * @return list<array{id: int, allocation_id: int, encounter_id: int, status: MatchStatus, conflict: bool, score: ?float, points: ?int, pass: ?int, contributions: array<string, int>, decided_by: ?string, decided_at: ?\DateTimeImmutable}>
     */
    public function rowsTouching(int $allocationId, array $encounterIds): array;
}
