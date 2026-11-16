<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\VitalsReference;

/**
 * Builds the hospital course of one ED visit: for the case card of an effective link (#1055) and for the
 * visit detail page (#1178), which needs no link. Pure: durations are recomputed from the visit's
 * timestamps exactly as in the overview (ProcessInterval), in whole minutes of the local wall clock.
 * Clinical times and durations come only from the visit; the allocation's arrival time is added to the
 * timeline as `ivena_arrival` when there is an effective link, and never enters a duration.
 */
final class HospitalCourseFactory
{
    public function create(EdVisit $visit, EncounterMatch $link): HospitalCourse
    {
        return $this->forEncounter($visit, $link);
    }

    /**
     * @param EncounterMatch|null $effectiveLink an automatic or confirmed link of this visit, or null
     */
    public function forEncounter(EdVisit $encounter, ?EncounterMatch $effectiveLink): HospitalCourse
    {
        if ($effectiveLink instanceof EncounterMatch && $effectiveLink->getEncounter() !== $encounter->getEncounter()
            && (null === $encounter->getId() || $effectiveLink->getEncounter()->getId() !== $encounter->getId())) {
            throw new \InvalidArgumentException('The link belongs to another visit.');
        }

        $times = $encounter->getTimes();
        $admission = $times->getEdAdmissionAt();
        $vitals = $encounter->getVitals();

        $vitalsOnTimeline = VitalsReference::AdmissionMeasurement === $vitals->getReference()
            && $vitals->getMeasuredAt() instanceof \DateTimeImmutable;

        $points = [
            'ivena_arrival' => $effectiveLink?->getAllocation()->getArrivalAt(),
            'ed_admission' => $admission,
            'first_assessment' => $times->getFirstAssessmentContactAt(),
            'first_physician' => $times->getFirstPhysicianAt(),
            'vitals_measured' => $vitalsOnTimeline ? $vitals->getMeasuredAt() : null,
            'treatment_end' => $times->getTreatmentEndAt(),
            'ed_discharge' => $times->getEdDischargeAt(),
        ];

        $timeline = [];
        foreach ($points as $key => $at) {
            if ($at instanceof \DateTimeImmutable) {
                $timeline[] = new TimelineEvent($key, $at, $this->minutes($admission, $at));
            }
        }

        // Stable: equal times keep the order of $points (arrival, admission, assessment, …).
        usort($timeline, static fn (TimelineEvent $a, TimelineEvent $b): int => self::wallClock($a->at) <=> self::wallClock($b->at));

        return new HospitalCourse(
            link: $effectiveLink,
            encounter: $encounter,
            timeline: $timeline,
            intervals: [
                new IntervalValue(ProcessInterval::AdmissionToAssessment, $this->minutes($admission, $times->getFirstAssessmentContactAt())),
                new IntervalValue(ProcessInterval::AdmissionToPhysician, $this->minutes($admission, $times->getFirstPhysicianAt())),
                new IntervalValue(ProcessInterval::AdmissionToDischarge, $this->minutes($admission, $times->getEdDischargeAt())),
            ],
            vitalsOnTimeline: $vitalsOnTimeline,
        );
    }

    private function minutes(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ?int
    {
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            return null;
        }

        return intdiv(self::wallClock($to) - self::wallClock($from), 60);
    }

    /**
     * Seconds of the local wall clock as exchanged, read as UTC: the same difference PostgreSQL
     * computes for `timestamp without time zone` columns in the overview.
     */
    private static function wallClock(\DateTimeImmutable $at): int
    {
        return new \DateTimeImmutable($at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))->getTimestamp();
    }
}
