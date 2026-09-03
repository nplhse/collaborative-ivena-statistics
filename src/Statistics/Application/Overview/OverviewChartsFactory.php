<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDistributionRow;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationHeatmapData;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardAssembler;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Overview\Dto\OverviewChartsViewModel;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewSliceData;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\Overview\OverviewSliceQuery;

final readonly class OverviewChartsFactory
{
    public function __construct(
        private OverviewSliceQuery $sliceQuery,
        private IndicationDashboardAssembler $indicationDashboardAssembler,
    ) {
    }

    public function build(
        OverviewQueryCriteria $criteria,
        OverviewDashboardMetricsResult $metrics,
    ): OverviewChartsViewModel {
        $slice = ($this->sliceQuery)($criteria);
        $total = $metrics->scopedTotal;

        $dayTimeHeatmap = $this->indicationDashboardAssembler->buildDayTimeHeatmap($slice->dayTimeHeatmapCells);
        $shiftHeatmap = $this->indicationDashboardAssembler->buildShiftHeatmap($slice->shiftHeatmapCells);
        $timeSeries = $this->indicationDashboardAssembler->buildTimeSeries(
            $slice->monthlyRows,
            $criteria->timeSeriesGrain,
            new StatisticsPeriodBounds($criteria->from, $criteria->toExclusive),
        );

        return new OverviewChartsViewModel(
            [
                'timeSeries' => [
                    'labels' => $timeSeries->labels,
                    'values' => $timeSeries->values,
                ],
                'heatmapDayTime' => $this->heatmapPayload($dayTimeHeatmap),
                'heatmapShift' => $this->heatmapPayload($shiftHeatmap),
            ],
            $this->indicationDashboardAssembler->buildAgeGroupDistribution($metrics->ageGroupCounts, $total),
            $this->buildTransportDistribution($slice, $total),
            $this->indicationDashboardAssembler->buildTransportTimeDistribution($slice->transportTimeBucketCounts, $total),
            $metrics->medianAge,
            $metrics->medianTransportMinutes,
        );
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    private function buildTransportDistribution(OverviewSliceData $slice, int $total): array
    {
        $rows = [];
        $counts = $slice->transportTypeBucketCounts;

        foreach (AllocationStatsTransportTypeProjectionCode::displayOrder() as $case) {
            $bucketKey = (string) $case->value;
            $count = $counts[$bucketKey] ?? 0;
            $label = match ($case) {
                AllocationStatsTransportTypeProjectionCode::Ground => 'stats.indication.transport.ground',
                AllocationStatsTransportTypeProjectionCode::Air => 'stats.indication.transport.air',
            };

            $rows[] = new IndicationDistributionRow(
                $label,
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $rows;
    }

    /**
     * @return array{rowLabels:list<string>,columnLabels:list<string>,matrix:list<list<int>>,max:int}
     */
    private function heatmapPayload(IndicationHeatmapData $heatmap): array
    {
        return [
            'rowLabels' => $heatmap->rowLabels,
            'columnLabels' => $heatmap->columnLabels,
            'matrix' => $heatmap->matrix,
            'max' => $heatmap->maxCount,
        ];
    }
}
