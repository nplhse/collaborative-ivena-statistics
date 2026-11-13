<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Process times of the encounter overview and the case card (#1055), recomputed from the exchanged
 * timestamps (minute precision); durations are never taken from the export.
 *
 * Every interval starts at the ED admission (`ed_admission_at`, "Aufnahme ZNA"), the documented
 * arrival in the emergency department. A duration counts when both timestamps exist and it lies
 * between 0 and MAX_PLAUSIBLE_MINUTES (both inclusive). Negative durations (end before admission) and
 * longer ones are excluded from median and IQR and counted separately.
 */
enum ProcessInterval: string
{
    /** ED admission → first assessment contact (start of the MTS triage contact). */
    case AdmissionToAssessment = 'admission_to_assessment';

    /** ED admission → first physician contact. */
    case AdmissionToPhysician = 'admission_to_physician';

    /** ED admission → ED discharge (leaving the emergency department, length of stay in the ED). */
    case AdmissionToDischarge = 'admission_to_discharge';

    /** 24 hours; longer durations are treated as implausible (documentation errors). */
    public const int MAX_PLAUSIBLE_MINUTES = 1440;

    public function isPlausible(int $minutes): bool
    {
        return $minutes >= 0 && $minutes <= self::MAX_PLAUSIBLE_MINUTES;
    }
}
