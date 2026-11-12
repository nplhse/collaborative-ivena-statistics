<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;

/**
 * Reads what the matcher compares, for the review's candidate lists (ADR 029, #1054).
 */
interface MatchCandidateReaderInterface
{
    /**
     * One allocation of the hospital, or null.
     */
    public function allocation(int $hospitalId, int $allocationId): ?MatchingAllocation;

    /**
     * Visits of the hospital whose matching anchor (ED admission, else first assessment contact) lies in
     * [from, to].
     *
     * @return list<MatchingEncounter>
     */
    public function encounters(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $ignoreImportId): array;
}
