<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardCriteria;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardHeader;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSliceQuery;

final readonly class IndicationDashboardService
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private IndicationDashboardMetricsQuery $metricsQuery,
        private IndicationDashboardSliceQuery $sliceQuery,
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
        $slice = $this->sliceQuery->fetch($indicationId, $from, $toExclusive, $scope);

        $total = $metrics->totalIndication;

        return new IndicationDashboardResult(
            new IndicationDashboardHeader(
                $indicationId,
                $indication->getName() ?? '',
                $indication->getCode(),
                $total,
            ),
            $this->assembler->buildSummaryDeck($slice->genderCounts, $metrics),
            $this->insightEngine->build($metrics),
            $this->assembler->buildTimeSeries($slice->monthlyRows),
            $this->assembler->buildDayTimeHeatmap($slice->dayTimeHeatmapCells),
            $this->assembler->buildShiftHeatmap($slice->shiftHeatmapCells),
            $this->assembler->buildResourcesDistribution($metrics),
            $this->assembler->buildTransportDistribution($metrics),
            $this->assembler->buildTransportTimeDistribution($slice->transportTimeBucketCounts, $total),
            $this->assembler->buildClinicalFeatures($metrics),
            $this->assembler->buildAgeGroupDistribution($slice->ageGroupCounts, $total),
            $metrics->medianAgeIndication,
            $metrics,
        );
    }
}
