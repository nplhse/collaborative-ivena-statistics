<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use Symfony\Component\Uid\Uuid;

/**
 * Reads ED visits with their links for the hospital course: the case card on an allocation (#1055) and
 * the visit detail page (#1178). Callers check access on the visit's hospital; nothing here filters by user.
 */
interface HospitalCourseReaderInterface
{
    /**
     * Effective link (automatic or confirmed, ADR 030) of one allocation, with its visit. Proposals and
     * rejected pairs are never returned.
     */
    public function effectiveLinkOfAllocation(int $allocationId): ?EncounterMatch;

    /** One visit by its `visit_uid`, with hospital and admitting department. */
    public function visitByUid(Uuid $visitUid): ?Encounter;

    /** Effective link of one visit, with its allocation; at most one exists (ADR 030). */
    public function effectiveLinkOfVisit(Encounter $encounter): ?EncounterMatch;

    /**
     * Open proposals (`proposed`) of one visit with their allocations, best score first. Review
     * information: only for callers that checked REVIEW on the hospital.
     *
     * @return list<EncounterMatch>
     */
    public function openProposalsOfVisit(Encounter $encounter): array;
}
