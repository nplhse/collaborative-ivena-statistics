<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\CaseFlow\Application\CaseFlowGeoKeyResolver;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationDashboardResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapChoroplethFeature;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapMarker;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationOverview;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use App\Statistics\HospitalPopulation\Application\DTO\RegionalCoverageRow;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetAllocationCountsPerHospitalQuery;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetHospitalIdsWithAllocationsQuery;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetHospitalPopulationQuery;

final readonly class HospitalPopulationDashboardService
{
    public function __construct(
        private GetHospitalPopulationQuery $populationQuery,
        private GetHospitalIdsWithAllocationsQuery $hospitalIdsWithAllocationsQuery,
        private GetAllocationCountsPerHospitalQuery $allocationCountsPerHospitalQuery,
        private HospitalPopulationSnapshotEnricher $snapshotEnricher,
        private CoverageCalculator $coverageCalculator,
        private DistributionTableBuilder $distributionTableBuilder,
        private DescriptiveStatisticsCalculator $descriptiveStatisticsCalculator,
        private AllocationBasisSummaryCalculator $allocationBasisSummaryCalculator,
        private BedsBoxPlotBuilder $bedsBoxPlotBuilder,
        private CaseFlowGeoKeyResolver $geoKeyResolver,
    ) {
    }

    public function build(): HospitalPopulationDashboardResult
    {
        $snapshots = $this->snapshotEnricher->enrich(
            ($this->populationQuery)(),
            ($this->hospitalIdsWithAllocationsQuery)(),
            ($this->allocationCountsPerHospitalQuery)(),
        );

        $totalHospitals = \count($snapshots);
        $participants = \count(array_filter(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): bool => $snapshot->isParticipating,
        ));

        $bedValues = array_map(static fn (HospitalPopulationSnapshot $snapshot): int => $snapshot->beds, $snapshots);
        $bedStats = $this->descriptiveStatisticsCalculator->calculate($bedValues);

        $tierKeys = array_map(
            static fn (HospitalTier $tier): string => $tier->value,
            HospitalTier::cases(),
        );
        $enumLabel = static fn (string $key): string => $key;

        $overview = new HospitalPopulationOverview(
            totalHospitals: $totalHospitals,
            participants: $participants,
            coverage: $this->coverageCalculator->calculate($participants, $totalHospitals),
            sizeByTierCrossTable: $this->distributionTableBuilder->buildCrossTable(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
                $tierKeys,
                array_map(static fn (HospitalSize $size): string => $size->value, HospitalSize::cases()),
                $enumLabel,
            ),
            urbanityByTierCrossTable: $this->distributionTableBuilder->buildCrossTable(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->urbanity->value,
                $tierKeys,
                array_map(static fn (HospitalLocation $location): string => $location->value, HospitalLocation::cases()),
                $enumLabel,
            ),
        );

        $participantBeds = array_map(
            static fn (HospitalPopulationSnapshot $snapshot): int => $snapshot->beds,
            array_values(array_filter(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): bool => $snapshot->isParticipating,
            )),
        );

        $regionalCoverage = $this->distributionTableBuilder->buildRegionalCoverageTable($snapshots);

        $bedsBoxPlotBreakdown = $this->bedsBoxPlotBuilder->build($snapshots);

        return new HospitalPopulationDashboardResult(
            overview: $overview,
            regionalCoverage: $regionalCoverage,
            bedsPopulation: $bedStats,
            bedsParticipants: $this->descriptiveStatisticsCalculator->calculate($participantBeds),
            allocationBasis: $this->allocationBasisSummaryCalculator->calculate($snapshots),
            mapMarkers: $this->buildMapMarkers($snapshots),
            mapChoropleth: $this->buildMapChoropleth($regionalCoverage),
            bedsBoxPlotByCareLevel: $bedsBoxPlotBreakdown->byCareLevel,
            bedsBoxPlotByLocation: $bedsBoxPlotBreakdown->byLocation,
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     *
     * @return list<HospitalPopulationMapMarker>
     */
    private function buildMapMarkers(array $snapshots): array
    {
        $markers = [];

        foreach ($snapshots as $snapshot) {
            if (null === $snapshot->latitude || null === $snapshot->longitude) {
                continue;
            }

            $markers[] = new HospitalPopulationMapMarker(
                id: $snapshot->id,
                name: $snapshot->name,
                latitude: $snapshot->latitude,
                longitude: $snapshot->longitude,
                beds: $snapshot->beds,
                careLevel: $snapshot->careLevel?->value,
                location: $snapshot->urbanity->value,
                isParticipating: $snapshot->isParticipating,
            );
        }

        return $markers;
    }

    /**
     * @param list<RegionalCoverageRow> $regionalCoverage
     *
     * @return list<HospitalPopulationMapChoroplethFeature>
     */
    private function buildMapChoropleth(array $regionalCoverage): array
    {
        $features = [];

        foreach ($regionalCoverage as $row) {
            $features[] = new HospitalPopulationMapChoroplethFeature(
                dispatchAreaId: $row->dispatchAreaId,
                landkreis: $row->dispatchAreaName,
                geoFeatureKey: $this->geoKeyResolver->resolve($row->dispatchAreaId, $row->dispatchAreaName),
                population: $row->population,
                participants: $row->participants,
                coverage: $row->coverage,
            );
        }

        return $features;
    }
}
