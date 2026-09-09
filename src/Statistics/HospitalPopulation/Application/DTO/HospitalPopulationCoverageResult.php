<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationCoverageResult
{
    /**
     * @param list<DistributionSummaryRow> $byCareLevel
     * @param list<DistributionSummaryRow> $bySize
     * @param list<DistributionSummaryRow> $byLocation
     * @param list<DistributionSummaryRow> $byState
     */
    public function __construct(
        public array $byCareLevel,
        public array $bySize,
        public array $byLocation,
        public array $byState,
        public CoverageCrossTable $sizeByTierCrossTable,
        public CoverageCrossTable $urbanityByTierCrossTable,
    ) {
    }
}
