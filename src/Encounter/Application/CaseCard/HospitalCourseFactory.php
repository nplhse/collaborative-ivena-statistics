<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\VitalsReference;

/**
 * Builds the case card of one effective link (#1055). Pure: durations are recomputed from the visit's
 * timestamps exactly as in the overview (ProcessInterval), in whole minutes of the local wall clock.
 */
final class HospitalCourseFactory
{
    public function create(EncounterMatch $link): HospitalCourse
    {
        $encounter = $link->getEncounter();
        $times = $encounter->getTimes();
        $admission = $times->getEdAdmissionAt();
        $vitals = $encounter->getVitals();

        $vitalsOnTimeline = VitalsReference::AdmissionMeasurement === $vitals->getReference()
            && $vitals->getMeasuredAt() instanceof \DateTimeImmutable;

        $points = [
            'ivena_arrival' => $link->getAllocation()->getArrivalAt(),
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
            link: $link,
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
