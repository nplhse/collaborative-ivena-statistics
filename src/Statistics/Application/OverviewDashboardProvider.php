<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Import\Infrastructure\Repository\ImportRepository;
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
        private ImportRepository $importRepository,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
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

        $allocationRaw = $this->timeSeriesQuery->countByMonthInPeriod(StatisticsPeriod::overviewPeriodStart(), null, null);
        $importRaw = $this->importRepository->countByMonthLast12Months();

        return $this->assembleChartPayload($monthKeys, $labels, $allocationRaw, $importRaw, $start);
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

        $allocationRaw = $this->timeSeriesQuery->countByMonthInPeriod($start, $toExclusive, null);
        $importRaw = $this->importRepository->countImportsByMonthInRange($start, $toExclusive);

        return $this->assembleChartPayload($monthKeys, $labels, $allocationRaw, $importRaw, $start);
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
        $start = $bounds->from;
        if ($bounds->toExclusive instanceof \DateTimeImmutable) {
            throw new \LogicException('all_time charts expect an open-ended upper bound.');
        }

        $earliest = $this->timeSeriesQuery->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            $firstMonth = $earliest->modify('first day of this month')->setTime(0, 0, 0);
            if ($firstMonth > $start) {
                $start = $firstMonth;
            }
        }

        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $monthKeys = [];
        $labels = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        $endExclusive = $currentMonth->modify('+1 month');
        $guard = 0;
        while ($cursor < $endExclusive && $guard < 600) {
            ++$guard;
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        if ([] === $monthKeys) {
            $monthKeys[] = $start->format('Y-m');
            $labels[] = $start->format('M');
        }

        $allocationRaw = $this->timeSeriesQuery->countByMonthInPeriod($start, null, null);
        $importRaw = $this->importRepository->countImportsByMonthInRange($start, null);

        return $this->assembleChartPayload($monthKeys, $labels, $allocationRaw, $importRaw, $start);
    }

    /**
     * @param list<string>                                         $monthKeys
     * @param list<string>                                         $labels
     * @param array<int, array{year: int, month: int, count: int}> $allocationRaw
     * @param array<int, array{year: int, month: int, count: int}> $importRaw
     *
     * @return array{
     *     allocationChart: array{labels: string[], monthlyCounts: int[], cumulativeCounts: int[]},
     *     importChart: array{labels: string[], monthlyCounts: int[]}
     * }
     */
    private function assembleChartPayload(
        array $monthKeys,
        array $labels,
        array $allocationRaw,
        array $importRaw,
        \DateTimeImmutable $rangeStartForCumulative,
    ): array {
        $mapMonthlyCounts = function (array $rawRows, array $keys): array {
            $base = array_fill_keys($keys, 0);

            foreach ($rawRows as $row) {
                $key = sprintf('%04d-%02d', $row['year'], $row['month']);
                if (\array_key_exists($key, $base)) {
                    $base[$key] = (int) $row['count'];
                }
            }

            return array_values($base);
        };

        $allocationMonthlyCounts = $mapMonthlyCounts($allocationRaw, $monthKeys);
        $importMonthlyCounts = $mapMonthlyCounts($importRaw, $monthKeys);
        $initialAllocations = $this->timeSeriesQuery->countBefore($rangeStartForCumulative);

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
