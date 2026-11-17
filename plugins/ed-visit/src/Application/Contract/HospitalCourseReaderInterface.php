<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Symfony\Component\Uid\Uuid;

/**
 * Reads ED visits with their links for the hospital course: the case card on an allocation (#1055) and
 * the visit detail page (#1178). Callers check access on the visit's hospital; nothing here filters by user.
 */
interface HospitalCourseReaderInterface
{
    /** One visit by its `visit_uid`, with its encounter, hospital and admitting department. */
    public function visitByUid(Uuid $visitUid): ?EdVisit;

    /** The ED visit of an encounter, if it has one (#1300). */
    public function visitOfEncounter(int $encounterId): ?EdVisit;

    /** Effective link of one visit in the ED matching history, with its allocation; at most one exists (ADR 030). */
    public function effectiveLinkOfVisit(EdVisit $visit): ?EncounterMatch;

    /**
     * Open proposals (`proposed`) of one visit with their allocations, best score first. Review
     * information: only for callers that checked REVIEW on the hospital.
     *
     * @return list<EncounterMatch>
     */
    public function openProposalsOfVisit(EdVisit $visit): array;
}
