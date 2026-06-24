<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\IndicationDashboard\DTO\IndicationDistributionRow;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationHeatmapData;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardAssembler;
use App\Statistics\Application\Overview\Dto\OverviewChartsViewModel;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;
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
        BenchmarkReport $benchmarkReport,
    ): OverviewChartsViewModel {
        $slice = ($this->sliceQuery)($criteria);
        $total = $metrics->scopedTotal;

        $dayTimeHeatmap = $this->indicationDashboardAssembler->buildDayTimeHeatmap($slice->dayTimeHeatmapCells);
        $shiftHeatmap = $this->indicationDashboardAssembler->buildShiftHeatmap($slice->shiftHeatmapCells);
        $timeSeries = $this->indicationDashboardAssembler->buildTimeSeries($slice->monthlyRows);

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
            $this->buildTransportDistribution($benchmarkReport, $total),
            $this->indicationDashboardAssembler->buildTransportTimeDistribution($slice->transportTimeBucketCounts, $total),
            $metrics->medianAge,
            $metrics->medianTransportMinutes,
        );
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    private function buildTransportDistribution(BenchmarkReport $benchmarkReport, int $total): array
    {
        $rows = [];

        foreach ($benchmarkReport->transportType->buckets as $bucket) {
            if ('unknown' === $bucket->key) {
                continue;
            }

            $rows[] = new IndicationDistributionRow(
                $bucket->label,
                $bucket->primaryCount,
                $total > 0 ? round(100 * $bucket->primaryCount / $total, 1) : 0.0,
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
