<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowKpiSet;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowOriginSlice;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowBaselineQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDestinationStructureQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowFlowMatrixQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowRegionalMetricsQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowTransportDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowRegionalMetricsRow;

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
    ) {
    }

    public function build(CaseFlowCriteria $criteria): CaseFlowDashboardResult
    {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;

        $metrics = $this->regionalMetricsQuery->fetch($from, $toExclusive, $scope);
        $originRows = $this->originDistributionQuery->fetch($from, $toExclusive, $scope);
        $baseline = $this->baselineQuery->fetchPublicBaseline($from, $toExclusive);

        $originSlices = $this->privacySuppressor->suppressOriginDistribution($originRows, $metrics->totalCases);
        $mapFeatures = $this->privacySuppressor->buildMapFeatures(
            $originRows,
            $metrics->totalCases,
            fn (int $id, string $name): string => $this->geoKeyResolver->resolve($id, $name),
        );

        $transportRows = $this->transportDistributionQuery->fetchTransportTime($from, $toExclusive, $scope);
        $urgencyRows = $this->transportDistributionQuery->fetchUrgency($from, $toExclusive, $scope);

        $flowMatrix = [];
        $destinationTierSlices = [];
        $destinationLocationSlices = [];
        $destinationSizeSlices = [];
        $destinationTierCard = null;
        $destinationLocationCard = null;
        $destinationSizeCard = null;

        if (CaseFlowMode::SystemFlow === $criteria->mode) {
            $flowMatrix = $this->privacySuppressor->suppressFlowMatrix(
                $this->flowMatrixQuery->fetch($from, $toExclusive, $scope),
            );
            $destinationTierSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchByTier($from, $toExclusive, $scope),
                $this->distributionBuilder->tierLabelKeys(),
            );
            $destinationLocationSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchByLocation($from, $toExclusive, $scope),
                $this->distributionBuilder->locationLabelKeys(),
            );
            $destinationSizeSlices = $this->privacySuppressor->suppressDestinationPools(
                $this->destinationStructureQuery->fetchBySize($from, $toExclusive, $scope),
                $this->distributionBuilder->sizeLabelKeys(),
            );
            $destinationTierCard = $this->structureDistributionAssembler->tierCard($destinationTierSlices);
            $destinationLocationCard = $this->structureDistributionAssembler->locationCard($destinationLocationSlices);
            $destinationSizeCard = $this->structureDistributionAssembler->sizeCard($destinationSizeSlices);
        }

        $kpis = $this->buildKpis($criteria->mode, $metrics, $originRows, $originSlices);

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
            $this->distributionBuilder->buildUrgency($urgencyRows, $metrics->totalCases),
            $mapFeatures,
            $baseline['medianTransport'],
            $baseline['fullTierPercent'],
        );
    }

    /**
     * @param list<CaseFlowOriginRow>   $originRows
     * @param list<CaseFlowOriginSlice> $originSlices
     */
    private function buildKpis(
        CaseFlowMode $mode,
        CaseFlowRegionalMetricsRow $metrics,
        array $originRows,
        array $originSlices,
    ): CaseFlowKpiSet {
        unset($originSlices);

        $total = $metrics->totalCases;
        $regionalShare = $total > 0 ? round(((float) $metrics->regionalCases / (float) $total) * 100.0, 1) : null;
        $centralization = $total > 0 ? round(((float) $metrics->fullTierCases / (float) $total) * 100.0, 1) : null;
        $overregional = $total > 0 ? round(((float) ($total - $metrics->regionalCases) / (float) $total) * 100.0, 1) : null;
        $emergencyShare = $total > 0 ? round(((float) $metrics->emergencyCases / (float) $total) * 100.0, 1) : null;

        $dominantName = null;
        $dominantShare = null;
        if ([] !== $originRows && $total > 0) {
            $top = $originRows[0];
            if ($top->caseCount >= CaseFlowPrivacyPolicy::MIN_CASES_PER_ORIGIN_BAR) {
                $dominantName = $top->originName;
                $dominantShare = round(((float) $top->caseCount / (float) $total) * 100.0, 1);
            }
        }

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
        );
    }
}
