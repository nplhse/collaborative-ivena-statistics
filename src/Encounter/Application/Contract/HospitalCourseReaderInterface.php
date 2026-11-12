<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\EncounterMatch;

/**
 * Reads the effective link (automatic or confirmed, ADR 029) of one allocation for the case card
 * "Hospital course" (#1055), with its visit. Proposals and rejected pairs are never returned.
 */
interface HospitalCourseReaderInterface
{
    public function effectiveLinkOfAllocation(int $allocationId): ?EncounterMatch;
}
