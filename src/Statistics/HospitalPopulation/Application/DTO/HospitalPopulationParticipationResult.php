<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationParticipationResult
{
    /**
     * @param list<RegionalCoverageRow>                    $regionalCoverage
     * @param list<HospitalPopulationMapMarker>            $mapMarkers
     * @param list<HospitalPopulationMapChoroplethFeature> $mapChoropleth
     */
    public function __construct(
        public HospitalPopulationKpis $kpis,
        public array $regionalCoverage,
        public array $mapMarkers,
        public array $mapChoropleth,
    ) {
    }
}
