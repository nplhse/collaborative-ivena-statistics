<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * The truth of generated visits by their keyed values, for comparing a matcher run on uploaded files
 * (#1052). A visit with a late assessment has two fingerprints: as first exported (anchored on the ED
 * admission) and once documented.
 */
final class SyntheticEncounterTruth
{
    /**
     * @return array{format: string, version: int, visits: list<array{case_key: string, visit_fingerprints: list<string>, kind: string, allocation_id: ?int, time_outlier: bool, age_difference: int, missing_admission: bool, late_assessment: bool}>, allocations_without_visit: list<int>}
     */
    public static function of(GeneratedEncounters $generated, SyntheticEncounterKey $key): array
    {
        $visits = [];
        foreach ($generated->visits as $visit) {
            $fingerprints = [$key->keysOf($visit->caseNumber, $visit->cellsAsExported(false))['visit_fingerprint']];
            if ($visit->lateAssessment) {
                $fingerprints[] = $key->keysOf($visit->caseNumber, $visit->cells)['visit_fingerprint'];
            }

            $visits[] = [
                'case_key' => $key->caseKey($visit->caseNumber),
                'visit_fingerprints' => $fingerprints,
                'kind' => $visit->kind->value,
                'allocation_id' => $visit->allocationId,
                'time_outlier' => $visit->timeOutlier,
                'age_difference' => $visit->ageDifference,
                'missing_admission' => '' === ($visit->cells['ed_admission_at'] ?? ''),
                'late_assessment' => $visit->lateAssessment,
            ];
        }

        return [
            'format' => 'synthetic-encounter-truth',
            'version' => 1,
            'visits' => $visits,
            'allocations_without_visit' => $generated->allocationsWithoutVisit,
        ];
    }
}
