<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\CaseFlow\Application\CaseFlowGeoKeyResolver;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTable;
use App\Statistics\HospitalPopulation\Application\DTO\DistributionSummaryRow;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationAllocationsResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationBedsResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationCoverageResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationKpis;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapChoroplethFeature;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationMapMarker;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationParticipationResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use App\Statistics\HospitalPopulation\Application\DTO\RegionalCoverageRow;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetAllocationCountsPerHospitalQuery;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetHospitalIdsWithAllocationsQuery;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetHospitalPopulationQuery;

final readonly class HospitalPopulationDashboardService
{
    private const string UNKNOWN_KEY = 'unknown';
    private const string UNKNOWN_LABEL = 'Unknown';

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

    public function buildParticipation(): HospitalPopulationParticipationResult
    {
        $snapshots = $this->loadSnapshots(withAllocationCounts: false);
        $regionalCoverage = $this->distributionTableBuilder->buildRegionalCoverageTable($snapshots);

        return new HospitalPopulationParticipationResult(
            kpis: $this->buildKpis($snapshots, $regionalCoverage),
            regionalCoverage: $regionalCoverage,
            mapMarkers: $this->buildMapMarkers($snapshots),
            mapChoropleth: $this->buildMapChoropleth($regionalCoverage),
        );
    }

    public function buildCoverage(): HospitalPopulationCoverageResult
    {
        $snapshots = $this->loadSnapshots(withAllocationCounts: false);
        $totalHospitals = \count($snapshots);
        $participants = $this->countParticipants($snapshots);
        $enumLabel = static fn (string $key): string => self::UNKNOWN_KEY === $key ? self::UNKNOWN_LABEL : $key;
        $tierKeys = HospitalTier::getValues();

        return new HospitalPopulationCoverageResult(
            byCareLevel: $this->withoutEmptyUnknownRows($this->buildDimensionSummaries(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->careLevel instanceof HospitalTier
                    ? $snapshot->careLevel->value
                    : self::UNKNOWN_KEY,
                [...$tierKeys, self::UNKNOWN_KEY],
                $enumLabel,
                $totalHospitals,
                $participants,
            )),
            bySize: $this->buildDimensionSummaries(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
                HospitalSize::getValues(),
                $enumLabel,
                $totalHospitals,
                $participants,
            ),
            byLocation: $this->buildDimensionSummaries(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->urbanity->value,
                HospitalLocation::getValues(),
                $enumLabel,
                $totalHospitals,
                $participants,
            ),
            byState: $this->buildDimensionSummaries(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->stateName,
                $this->orderedStateNames($snapshots),
                $enumLabel,
                $totalHospitals,
                $participants,
            ),
            sizeByTierCrossTable: $this->buildSizeByTierCrossTable($snapshots, $tierKeys, $enumLabel),
            urbanityByTierCrossTable: $this->buildUrbanityByTierCrossTable($snapshots, $tierKeys, $enumLabel),
        );
    }

    public function buildBeds(): HospitalPopulationBedsResult
    {
        $snapshots = $this->loadSnapshots(withAllocationCounts: false);
        $bedValues = array_map(static fn (HospitalPopulationSnapshot $snapshot): int => $snapshot->beds, $snapshots);
        $participantBeds = array_map(
            static fn (HospitalPopulationSnapshot $snapshot): int => $snapshot->beds,
            array_values(array_filter(
                $snapshots,
                static fn (HospitalPopulationSnapshot $snapshot): bool => $snapshot->isParticipating,
            )),
        );
        $bedsBoxPlotBreakdown = $this->bedsBoxPlotBuilder->build($snapshots);

        return new HospitalPopulationBedsResult(
            bedsPopulation: $this->descriptiveStatisticsCalculator->calculate($bedValues),
            bedsParticipants: $this->descriptiveStatisticsCalculator->calculate($participantBeds),
            bedsBoxPlotByCareLevel: $bedsBoxPlotBreakdown->byCareLevel,
            bedsBoxPlotByLocation: $bedsBoxPlotBreakdown->byLocation,
        );
    }

    public function buildAllocations(): HospitalPopulationAllocationsResult
    {
        return new HospitalPopulationAllocationsResult(
            allocationBasis: $this->allocationBasisSummaryCalculator->calculate(
                $this->loadSnapshots(withAllocationCounts: true),
            ),
        );
    }

    /**
     * @return list<HospitalPopulationSnapshot>
     */
    private function loadSnapshots(bool $withAllocationCounts): array
    {
        return $this->snapshotEnricher->enrich(
            ($this->populationQuery)(),
            ($this->hospitalIdsWithAllocationsQuery)(),
            $withAllocationCounts ? ($this->allocationCountsPerHospitalQuery)() : [],
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     * @param list<RegionalCoverageRow>        $regionalCoverage
     */
    private function buildKpis(array $snapshots, array $regionalCoverage): HospitalPopulationKpis
    {
        $totalHospitals = \count($snapshots);
        $participants = $this->countParticipants($snapshots);
        $dispatchAreasTotal = \count($regionalCoverage);
        $dispatchAreasRepresented = \count(array_filter(
            $regionalCoverage,
            static fn (RegionalCoverageRow $row): bool => $row->participants > 0,
        ));

        return new HospitalPopulationKpis(
            totalHospitals: $totalHospitals,
            participants: $participants,
            coverage: $this->coverageCalculator->calculate($participants, $totalHospitals),
            dispatchAreasTotal: $dispatchAreasTotal,
            dispatchAreasRepresented: $dispatchAreasRepresented,
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot>             $snapshots
     * @param callable(HospitalPopulationSnapshot): string $keyResolver
     * @param list<string>                                 $orderedKeys
     * @param callable(string): string                     $labelResolver
     *
     * @return list<DistributionSummaryRow>
     */
    private function buildDimensionSummaries(
        array $snapshots,
        callable $keyResolver,
        array $orderedKeys,
        callable $labelResolver,
        int $totalHospitals,
        int $participants,
    ): array {
        $coverageRows = $this->distributionTableBuilder->buildCategoryTable(
            $snapshots,
            $keyResolver,
            $labelResolver,
            $orderedKeys,
        );

        return $this->distributionTableBuilder->buildDistributionSummaries(
            $snapshots,
            $coverageRows,
            $totalHospitals,
            $participants,
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     * @param list<string>                     $tierKeys
     * @param callable(string): string         $labelResolver
     */
    private function buildSizeByTierCrossTable(
        array $snapshots,
        array $tierKeys,
        callable $labelResolver,
    ): CoverageCrossTable {
        return $this->distributionTableBuilder->buildCrossTable(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
            $tierKeys,
            HospitalSize::getValues(),
            $labelResolver,
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     * @param list<string>                     $tierKeys
     * @param callable(string): string         $labelResolver
     */
    private function buildUrbanityByTierCrossTable(
        array $snapshots,
        array $tierKeys,
        callable $labelResolver,
    ): CoverageCrossTable {
        return $this->distributionTableBuilder->buildCrossTable(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->urbanity->value,
            $tierKeys,
            HospitalLocation::getValues(),
            $labelResolver,
        );
    }

    /**
     * @param list<DistributionSummaryRow> $rows
     *
     * @return list<DistributionSummaryRow>
     */
    private function withoutEmptyUnknownRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (DistributionSummaryRow $row): bool => self::UNKNOWN_KEY !== $row->key || $row->population > 0,
        ));
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     *
     * @return list<string>
     */
    private function orderedStateNames(array $snapshots): array
    {
        $names = array_values(array_unique(array_map(
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->stateName,
            $snapshots,
        )));
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     */
    private function countParticipants(array $snapshots): int
    {
        return \count(array_filter(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): bool => $snapshot->isParticipating,
        ));
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
