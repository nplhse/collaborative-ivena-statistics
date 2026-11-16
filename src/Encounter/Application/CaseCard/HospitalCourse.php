<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Hospital course of one ED visit: triage, process times, disposition and initial vitals, with the link
 * origin when the visit has an effective link. Shown as the case card of an allocation (#1055, always with
 * a link) and on the visit detail page (#1178, link optional).
 *
 * Vitals with the reference `documented_initial` are documented first values without an assured time
 * reference: `vitalsOnTimeline` is false and they never appear on the timeline. Only
 * `admission_measurement` with a measurement time is placed there.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the case card template.
 */
final readonly class HospitalCourse
{
    /**
     * @param list<TimelineEvent> $timeline  ordered by time, IVENA arrival included
     * @param list<IntervalValue> $intervals
     */
    public function __construct(
        public ?EncounterMatch $link,
        public EdVisit $encounter,
        public array $timeline,
        public array $intervals,
        public bool $vitalsOnTimeline,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the template. */
    public function isAutomatic(): bool
    {
        return MatchStatus::AutoAccepted === $this->link?->getStatus();
    }

    /**
     * Agreement score in percent (0–100), null without link or when the pair has no score.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the template.
     */
    public function agreementPercent(): ?float
    {
        $score = $this->link?->getScore();

        return null === $score ? null : $score * 100.0;
    }
}
