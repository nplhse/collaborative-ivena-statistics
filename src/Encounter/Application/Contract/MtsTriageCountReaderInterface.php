<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Glossary\EncounterHospitalScope;

/**
 * Encounter counts per MTS code of the initial triage (`mts_*`) for the MTS glossary (#1056).
 * Retriage values are not counted. Every method counts only encounters of the hospitals in the scope.
 */
interface MtsTriageCountReaderInterface
{
    /**
     * @return array<string, int> count per category code
     */
    public function countByCategory(EncounterHospitalScope $scope): array;

    /**
     * @return array<string, int> count per diagram code
     */
    public function countByDiagram(EncounterHospitalScope $scope): array;

    /**
     * @return array<string, int> count per indicator code
     */
    public function countByIndicator(EncounterHospitalScope $scope): array;

    /**
     * Indicators seen with the diagram; key `''` counts encounters of the diagram without an indicator.
     *
     * @return array<string, int>
     */
    public function countIndicatorsOfDiagram(EncounterHospitalScope $scope, string $diagram): array;
}
