<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Contract\ImportTimelineInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\ChartPairWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

final readonly class OverviewDashboardProvider
{
    public function __construct(
        private ImportTimelineInterface $importTimeline,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private ChartBucketMapper $chartBucketMapper,
    ) {
    }

    /**
     * @return list<StatisticWidget>
     */
    public function build(StatisticsContext $context): array
    {
        $charts = $this->buildDashboardCharts($context);

        return [
            new StatisticWidget(
                StatisticWidgetType::ChartPair,
                'chart_pair_overview',
                $this->widgetPayloadNormalizer->normalize(
                    new ChartPairWidgetPayload(
                        [
                            'labels' => array_values($charts['allocationChart']['labels']),
                            'monthlyCounts' => array_values($charts['allocationChart']['monthlyCounts']),
                            'cumulativeCounts' => array_values($charts['allocationChart']['cumulativeCounts']),
                        ],
                        [
                            'labels' => array_values($charts['importChart']['labels']),
                            'monthlyCounts' => array_values($charts['importChart']['monthlyCounts']),
                        ],
                    )
                ),
            ),
        ];
    }

    /**
     * @return array{
     *     allocationChart: array{
     *         labels: string[],
     *         monthlyCounts: int[],
     *         cumulativeCounts: int[]
     *     },
     *     importChart: array{
     *         labels: string[],
     *         monthlyCounts: int[]
     *     }
     * }
     */
    private function buildDashboardCharts(StatisticsContext $context): array
    {
        if (StatisticsFilterPeriod::All === $context->filter->period) {
            return $this->buildRolling12MonthCharts();
        }

        if (StatisticsFilterPeriod::AllTime === $context->filter->period) {
            return $this->buildAllTimeCharts($context);
        }

        return $this->buildBoundedPeriodCharts($context);
    }

    /**
     * @return array{
     *     allocationChart: array{labels: string[], monthlyCounts: int[], cumulativeCounts: int[]},
     *     importChart: array{labels: string[], monthlyCounts: int[]}
     * }
     */
    private function buildRolling12MonthCharts(): array
    {
        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $start = StatisticsPeriod::overviewPeriodStart();

        $monthKeys = [];
        $labels = [];
        $cursor = $start;
        while ($cursor <= $currentMonth) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        $allocationRows = $this->timeSeriesQuery->countByMonthInPeriod(StatisticsPeriod::overviewPeriodStart(), null, null);
        $importRows = $this->importTimeline->countByMonthLast12Months();

        return $this->assembleChartPayload(
            $monthKeys,
            $labels,
            $this->chartBucketMapper->monthRowsToBucketCounts($allocationRows),
            $this->chartBucketMapper->monthRowsToBucketCounts($importRows),
            $start
        );
    }

    /**
     * @return array{
     *     allocationChart: array{labels: string[], monthlyCounts: int[], cumulativeCounts: int[]},
     *     importChart: array{labels: string[], monthlyCounts: int[]}
     * }
     */
    private function buildBoundedPeriodCharts(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $start = $bounds->from;
        $toExclusive = $bounds->toExclusive;
        \assert($start instanceof \DateTimeImmutable);
        \assert($toExclusive instanceof \DateTimeImmutable);

        $monthKeys = [];
        $labels = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        while ($cursor < $toExclusive) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        if ([] === $monthKeys) {
            $monthKeys[] = $start->format('Y-m');
            $labels[] = $start->format('M');
        }

        $allocationRows = $this->timeSeriesQuery->countByMonthInPeriod($start, $toExclusive, null);
        $importRows = $this->importTimeline->countByMonthInRange($start, $toExclusive);

        return $this->assembleChartPayload(
            $monthKeys,
            $labels,
            $this->chartBucketMapper->monthRowsToBucketCounts($allocationRows),
            $this->chartBucketMapper->monthRowsToBucketCounts($importRows),
            $start
        );
    }

    /**
     * Charts over full data history (month buckets from earliest allocation through the current month).
     *
     * @return array{
     *     allocationChart: array{labels: string[], monthlyCounts: int[], cumulativeCounts: int[]},
     *     importChart: array{labels: string[], monthlyCounts: int[]}
     * }
     */
    private function buildAllTimeCharts(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        if ($bounds->toExclusive instanceof \DateTimeImmutable) {
            throw new \LogicException('all_time charts expect an open-ended upper bound.');
        }

        $start = $bounds->from;
        $earliest = $this->timeSeriesQuery->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            $firstMonth = $earliest->modify('first day of this month')->setTime(0, 0, 0);
            if (!$start instanceof \DateTimeImmutable || $firstMonth > $start) {
                $start = $firstMonth;
            }
        }
        if (!$start instanceof \DateTimeImmutable) {
            $start = new \DateTimeImmutable('first day of this month')->setTime(0, 0, 0);
        }

        $currentYear = (int) new \DateTimeImmutable('now')->format('Y');
        $startYear = (int) $start->format('Y');
        $yearKeys = [];
        $labels = [];
        for ($year = $startYear; $year <= $currentYear; ++$year) {
            $key = (string) $year;
            $yearKeys[] = $key;
            $labels[] = $key;
        }

        if ([] === $yearKeys) {
            $key = (string) $startYear;
            $yearKeys[] = $key;
            $labels[] = $key;
        }

        $rangeStart = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $startYear));
        $allocationRows = $this->timeSeriesQuery->countGroupedByCreatedYear($startYear, null, null);
        $importRows = $this->importTimeline->countByYearInRange($rangeStart, null);

        return $this->assembleChartPayload(
            $yearKeys,
            $labels,
            $this->chartBucketMapper->yearRowsToBucketCounts($allocationRows),
            $this->chartBucketMapper->yearRowsToBucketCounts($importRows),
            $this->timeSeriesQuery->countWithCreatedYearBefore($startYear),
        );
    }

    /**
     * @param list<string>      $bucketKeys
     * @param list<string>      $labels
     * @param array<string,int> $allocationBucketCounts
     * @param array<string,int> $importBucketCounts
     *
     * @return array{
     *     allocationChart: array{labels: string[], monthlyCounts: int[], cumulativeCounts: int[]},
     *     importChart: array{labels: string[], monthlyCounts: int[]}
     * }
     */
    private function assembleChartPayload(
        array $bucketKeys,
        array $labels,
        array $allocationBucketCounts,
        array $importBucketCounts,
        \DateTimeImmutable|int $cumulativeBaseline,
    ): array {
        $allocationMonthlyCounts = $this->chartBucketMapper->mapMonthlyCounts($bucketKeys, $allocationBucketCounts);
        $importMonthlyCounts = $this->chartBucketMapper->mapMonthlyCounts($bucketKeys, $importBucketCounts);
        $initialAllocations = \is_int($cumulativeBaseline)
            ? $cumulativeBaseline
            : $this->timeSeriesQuery->countBefore($cumulativeBaseline);

        $allocationCumulativeCounts = [];
        $runningTotal = $initialAllocations;
        foreach ($allocationMonthlyCounts as $value) {
            $runningTotal += $value;
            $allocationCumulativeCounts[] = $runningTotal;
        }

        return [
            'allocationChart' => [
                'labels' => $labels,
                'monthlyCounts' => $allocationMonthlyCounts,
                'cumulativeCounts' => $allocationCumulativeCounts,
            ],
            'importChart' => [
                'labels' => $labels,
                'monthlyCounts' => $importMonthlyCounts,
            ],
        ];
    }
}
