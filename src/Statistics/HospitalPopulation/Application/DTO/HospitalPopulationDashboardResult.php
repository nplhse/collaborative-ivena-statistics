<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationDashboardResult
{
    /**
     * @param list<RegionalCoverageRow>                    $regionalCoverage
     * @param list<HospitalPopulationMapMarker>            $mapMarkers
     * @param list<HospitalPopulationMapChoroplethFeature> $mapChoropleth
     */
    public function __construct(
        public HospitalPopulationOverview $overview,
        public array $regionalCoverage,
        public DescriptiveStats $bedsPopulation,
        public DescriptiveStats $bedsParticipants,
        public AllocationBasisSummary $allocationBasis,
        public array $mapMarkers,
        public array $mapChoropleth,
        /** @var list<BedsCategoryBoxPlotRow> $bedsBoxPlotByCareLevel */
        public array $bedsBoxPlotByCareLevel = [],
        /** @var list<BedsCategoryBoxPlotRow> $bedsBoxPlotByLocation */
        public array $bedsBoxPlotByLocation = [],
    ) {
    }
}
