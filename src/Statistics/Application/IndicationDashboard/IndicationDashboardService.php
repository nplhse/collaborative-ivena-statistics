<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardCriteria;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardHeader;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardDemographicsQuery;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardTemporalQuery;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardTimeSeriesQuery;

final readonly class IndicationDashboardService
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private IndicationDashboardMetricsQuery $metricsQuery,
        private IndicationDashboardTimeSeriesQuery $timeSeriesQuery,
        private IndicationDashboardDemographicsQuery $demographicsQuery,
        private IndicationDashboardTemporalQuery $temporalQuery,
        private IndicationInsightEngine $insightEngine,
        private IndicationDashboardAssembler $assembler,
    ) {
    }

    public function build(IndicationDashboardCriteria $criteria): ?IndicationDashboardResult
    {
        $indication = $this->indicationRepository->find($criteria->indicationId);
        if (!$indication instanceof IndicationNormalized) {
            return null;
        }

        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;
        $indicationId = $criteria->indicationId;

        $metrics = $this->metricsQuery->fetch($indicationId, $from, $toExclusive, $scope);

        $monthly = $this->timeSeriesQuery->countByMonth($indicationId, $from, $toExclusive, $scope);
        $genderCounts = $this->demographicsQuery->genderCounts($indicationId, $from, $toExclusive, $scope);
        $ageGroups = $this->demographicsQuery->ageGroupCounts($indicationId, $from, $toExclusive, $scope);
        $transportTimeBuckets = $this->demographicsQuery->transportTimeBucketCounts($indicationId, $from, $toExclusive, $scope);
        $dayTimeHeatmapCells = $this->temporalQuery->heatmapCells($indicationId, $from, $toExclusive, $scope);
        $shiftHeatmapCells = $this->temporalQuery->shiftHeatmapCells($indicationId, $from, $toExclusive, $scope);

        $total = $metrics->totalIndication;

        return new IndicationDashboardResult(
            new IndicationDashboardHeader(
                $indicationId,
                $indication->getName() ?? '',
                $indication->getCode(),
                $total,
            ),
            $this->assembler->buildSummaryDeck($genderCounts, $metrics),
            $this->insightEngine->build($metrics),
            $this->assembler->buildTimeSeries($monthly),
            $this->assembler->buildDayTimeHeatmap($dayTimeHeatmapCells),
            $this->assembler->buildShiftHeatmap($shiftHeatmapCells),
            $this->assembler->buildResourcesDistribution($metrics),
            $this->assembler->buildTransportDistribution($metrics),
            $this->assembler->buildTransportTimeDistribution($transportTimeBuckets, $total),
            $this->assembler->buildClinicalFeatures($metrics),
            $this->assembler->buildAgeGroupDistribution($ageGroups, $total),
            $metrics->medianAgeIndication,
            $metrics,
        );
    }
}
