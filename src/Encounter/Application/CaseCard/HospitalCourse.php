<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Case card "Hospital course" of one allocation (#1055): its linked ED visit with link origin,
 * triage, process times, disposition and initial vitals.
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
        public EncounterMatch $link,
        public Encounter $encounter,
        public array $timeline,
        public array $intervals,
        public bool $vitalsOnTimeline,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the template. */
    public function isAutomatic(): bool
    {
        return MatchStatus::AutoAccepted === $this->link->getStatus();
    }

    /**
     * Agreement score in percent (0–100), null when the pair has no score.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the template.
     */
    public function agreementPercent(): ?float
    {
        $score = $this->link->getScore();

        return null === $score ? null : $score * 100.0;
    }
}
