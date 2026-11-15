<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\VisitList\VisitListFilter;

/**
 * Visit list of one hospital (#1178): exactly one row per visit, whatever the number of match candidates.
 * Callers check VIEW_CASES on the hospital; the hospital is always part of the query.
 */
interface VisitListReaderInterface
{
    public const array SORT_KEYS = ['anchor', 'age'];

    /**
     * @param 'anchor'|'age' $sortBy
     * @param 'asc'|'desc'   $orderBy
     *
     * @return list<array{
     *     encounter_id: int, visit_uid: string, anchor_at: \DateTimeImmutable, substitute: bool, age: int, sex: string,
     *     arrival_route: string, arrival_mode: ?string, mts_category: ?string, mts_diagram: ?string, disposition: ?string,
     *     referral_source: ?string, case_status: ?string, department: ?string,
     *     link_state: string, allocation_id: ?int, allocation_public_id: ?string
     * }>
     */
    public function visits(VisitListFilter $filter, string $sortBy, string $orderBy, int $offset, int $limit): array;

    public function countVisits(VisitListFilter $filter): int;

    /**
     * Hospitals for the hospital choice, by name: the given ids, or every hospital with visits when null.
     *
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{id: int, public_id: string, name: string}>
     */
    public function hospitals(?array $hospitalIds): array;

    /**
     * MTS diagram codes that occur in the hospital's visits, for the diagram filter.
     *
     * @return list<string>
     */
    public function diagramCodes(int $hospitalId): array;
}
