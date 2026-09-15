<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginHeatmapAssembler;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDispatchAreaFlow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowKpiSet;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowBaselineQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDestinationStructureQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowFlowMatrixQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowRegionalMetricsQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowTransportDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowRegionalMetricsRow;
use App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalQuery;

final readonly class CaseFlowDashboardService
{
    public function __construct(
        private CaseFlowRegionalMetricsQuery $regionalMetricsQuery,
        private CaseFlowOriginDistributionQuery $originDistributionQuery,
        private CaseFlowFlowMatrixQuery $flowMatrixQuery,
        private CaseFlowDestinationStructureQuery $destinationStructureQuery,
        private CaseFlowTransportDistributionQuery $transportDistributionQuery,
        private CaseFlowBaselineQuery $baselineQuery,
        private CaseFlowPrivacySuppressor $privacySuppressor,
        private CaseFlowDistributionBuilder $distributionBuilder,
        private CaseFlowStructureDistributionAssembler $structureDistributionAssembler,
        private CaseFlowInsightEngine $insightEngine,
        private CaseFlowGeoKeyResolver $geoKeyResolver,
        private GeographicDestinationHospitalQuery $destinationHospitalQuery,
        private IsochroneOriginHeatmapAssembler $isochroneAssembler,
    ) {
    }

    public function build(CaseFlowCriteria $criteria): CaseFlowDashboardResult
    {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;
        $originStateId = $criteria->originStateId();
        $drawerFilter = $criteria->drawerFilter;

        $originMatch = $criteria->isDispatchAreaScope()
            ? CaseFlowDispatchAreaMatch::Catchment
            : CaseFlowDispatchAreaMatch::Related;

        $metrics = $this->regionalMetricsQuery->fetch($from, $toExclusive, $scope, $originStateId, $drawerFilter);
        $originRows = $this->originDistributionQuery->fetch(
            $from,
            $toExclusive,
            $scope,
            $originStateId,
            $drawerFilter,
            $originMatch,
        );
        $baseline = $this->baselineQuery->fetchPublicBaseline($from, $toExclusive);

        $originTotal = array_sum(array_map(
            static fn (CaseFlowOriginRow $row): int => $row->caseCount,
            $originRows,
        ));
        $originSlices = $this->privacySuppressor->suppressOriginDistribution($originRows, $originTotal);
        $mapFeatures = $this->privacySuppressor->buildMapFeatures(
            $originRows,
            $originTotal,
            fn (int $id, string $name): string => $this->geoKeyResolver->resolve($id, $name),
        );

        $transportRows = $this->transportDistributionQuery->fetchTransportTime($from, $toExclusive, $scope, $originStateId, $drawerFilter);

        $flowMatrix = [];
        $destinationTierSlices = [];
        $destinationLocationSlices = [];
        $destinationSizeSlices = [];
        $destinationTierCard = null;
        $destinationLocationCard = null;
        $destinationSizeCard = null;
        $destinationHospitals = [];
        $omittedInsideDestinationHospitals = 0;
        $omittedOutsideDestinationHospitals = 0;

        if (CaseFlowMode::SystemFlow === $criteria->mode) {
            $flowMatrix = $this->privacySuppressor->suppressFlowMatrix(
                $this->flowMatrixQuery->fetch($from, $toExclusive, $scope, $originStateId, $drawerFilter),
            );
            $destinationTierSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchByTier($from, $toExclusive, $scope, $originStateId, $drawerFilter),
                $this->distributionBuilder->tierLabelKeys(),
            );
            $destinationLocationSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchByLocation($from, $toExclusive, $scope, $originStateId, $drawerFilter),
                $this->distributionBuilder->locationLabelKeys(),
            );
            $destinationSizeSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchBySize($from, $toExclusive, $scope, $originStateId, $drawerFilter),
                $this->distributionBuilder->sizeLabelKeys(),
            );
            $destinationTierCard = $this->structureDistributionAssembler->tierCard($destinationTierSlices);
            $destinationLocationCard = $this->structureDistributionAssembler->locationCard($destinationLocationSlices);
            $destinationSizeCard = $this->structureDistributionAssembler->sizeCard($destinationSizeSlices);
            $destinationHospitalPins = $this->privacySuppressor->suppressDestinationHospitals(
                $this->destinationHospitalQuery->fetch($from, $toExclusive, $scope, $originStateId, $drawerFilter),
                $metrics->totalCases,
                $criteria->isDispatchAreaScope() ? $scope->dispatchAreaId : null,
            );
            $destinationHospitals = $destinationHospitalPins->pins;
            $omittedInsideDestinationHospitals = $destinationHospitalPins->omittedInsideCount;
            $omittedOutsideDestinationHospitals = $destinationHospitalPins->omittedOutsideCount;
        }

        $isochrone = null;
        if ($criteria->isSingleHospital()) {
            $isochrone = $this->isochroneAssembler->build(
                $criteria->filter,
                $scope,
                $criteria->period,
                null,
                $drawerFilter?->departmentWasClosed,
                $drawerFilter,
            );
        }

        $kpis = $this->buildKpis($criteria->mode, $metrics, $originRows, $originTotal);
        $dispatchAreaFlow = $criteria->isDispatchAreaScope()
            ? $this->buildAssignmentFlow($metrics, showDestinationSplit: true)
            : null;
        $hospitalFlow = $criteria->isSingleHospital()
            ? $this->buildAssignmentFlow($metrics, showDestinationSplit: false)
            : null;

        return new CaseFlowDashboardResult(
            $criteria->mode,
            $kpis,
            $this->insightEngine->build($criteria->mode, $metrics, $originRows, $baseline),
            $originSlices,
            $flowMatrix,
            $destinationTierSlices,
            $destinationLocationSlices,
            $destinationSizeSlices,
            $destinationTierCard,
            $destinationLocationCard,
            $destinationSizeCard,
            $this->distributionBuilder->buildTransportTime($transportRows, $metrics->totalCases),
            $mapFeatures,
            $baseline['medianTransport'],
            $baseline['fullTierPercent'],
            $destinationHospitals,
            $isochrone,
            $criteria->isSingleHospital(),
            $criteria->filter->scope,
            $dispatchAreaFlow,
            $hospitalFlow,
            $criteria->isDispatchAreaScope() ? $scope->dispatchAreaId : null,
            $omittedInsideDestinationHospitals,
            $omittedOutsideDestinationHospitals,
        );
    }

    /**
     * @param list<CaseFlowOriginRow> $originRows
     */
    private function buildKpis(
        CaseFlowMode $mode,
        CaseFlowRegionalMetricsRow $metrics,
        array $originRows,
        int $originTotal,
    ): CaseFlowKpiSet {
        $total = $metrics->totalCases;
        $regionalShare = $total > 0 ? round(((float) $metrics->regionalCases / (float) $total) * 100.0, 1) : null;
        $centralization = $total > 0 ? round(((float) $metrics->fullTierCases / (float) $total) * 100.0, 1) : null;
        $overregional = $total > 0 ? round(((float) ($total - $metrics->regionalCases) / (float) $total) * 100.0, 1) : null;
        $emergencyShare = $total > 0 ? round(((float) $metrics->emergencyCases / (float) $total) * 100.0, 1) : null;

        $dominantName = null;
        $dominantShare = null;
        $dominantDenominator = $originTotal > 0 ? $originTotal : $total;
        if ([] !== $originRows && $dominantDenominator > 0) {
            $top = $originRows[0];
            if ($top->caseCount >= CaseFlowPrivacyPolicy::MIN_CASES_PER_ORIGIN_BAR) {
                $dominantName = $top->originName;
                $dominantShare = round(((float) $top->caseCount / (float) $dominantDenominator) * 100.0, 1);
            }
        }

        $inflowShare = $total > 0 ? round(((float) $metrics->inflowCases / (float) $total) * 100.0, 1) : null;
        $outflowShare = $total > 0 ? round(((float) $metrics->outflowCases / (float) $total) * 100.0, 1) : null;

        if (CaseFlowMode::HospitalOrigin === $mode) {
            return new CaseFlowKpiSet(
                $total,
                $regionalShare,
                null,
                $metrics->meanTransportMinutes,
                $dominantName,
                $dominantShare,
                $overregional,
                $emergencyShare,
            );
        }

        return new CaseFlowKpiSet(
            $total,
            $regionalShare,
            $centralization,
            $metrics->meanTransportMinutes,
            $dominantName,
            $dominantShare,
            null,
            $emergencyShare,
            $inflowShare,
            $outflowShare,
        );
    }

    private function buildAssignmentFlow(
        CaseFlowRegionalMetricsRow $metrics,
        bool $showDestinationSplit,
    ): CaseFlowDispatchAreaFlow {
        $total = $metrics->totalCases;
        $local = $metrics->regionalCases;
        $outflow = $showDestinationSplit ? $metrics->outflowCases : 0;
        $inflow = $showDestinationSplit ? $metrics->inflowCases : max(0, $total - $local);
        $percent = (static fn (int $count): ?float => $total > 0 ? round(((float) $count / (float) $total) * 100.0, 1) : null);

        return new CaseFlowDispatchAreaFlow(
            $inflow,
            $local,
            $outflow,
            $total,
            $percent($inflow),
            $percent($local),
            $percent($outflow),
            $percent($inflow + $local),
            $percent($local + $outflow),
            $showDestinationSplit,
        );
    }
}
