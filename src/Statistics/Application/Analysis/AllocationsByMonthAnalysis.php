<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Statistics\AnalysisExplorer\Application\ExplorerLegacyAnalyticsViewMapper;
use App\Statistics\Application\AllocationsByMonthQuery;
use App\Statistics\Application\DTO\AllocationsOverTimeSeries;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\SimpleChartWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AllocationsByMonthAnalysis implements AnalysisDefinitionInterface
{
    public function __construct(
        private AllocationsByMonthQuery $allocationsByMonthQuery,
        private TranslatorInterface $translator,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private ExplorerLegacyAnalyticsViewMapper $legacyViewMapper,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'allocations_by_month';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.analysis.allocations_by_month.label';
    }

    #[\Override]
    public function supports(StatisticsContext $context): bool
    {
        return true;
    }

    #[\Override]
    public function isPivotLike(): bool
    {
        return false;
    }

    #[\Override]
    public function build(
        StatisticsContext $context,
        string $view,
        string $chartType,
        StatisticsAnalysisDimension $dimension,
        StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute,
    ): StatisticWidget {
        $series = $this->allocationsByMonthQuery->fetch($context, $dimension);

        $n = \count($series->monthKeys);

        $totalsPerMonth = $series->totalsPerMonth();
        $summaryStats = $this->formatSummaryStats($totalsPerMonth);

        /** @var array<string, int> $monthTotalsByKey */
        $monthTotalsByKey = [];
        if ($this->usesPlainCountColumnsTable($dimension)) {
            $totalSeries = $this->allocationsByMonthQuery->fetch($context, StatisticsAnalysisDimension::Total);
            $monthTotalsByKey = $this->monthTotalsByKeyMap($totalSeries);
        }

        if ('table' === $view) {
            if ($this->usesPlainCountColumnsTable($dimension)) {
                $plainTablePayload = $this->buildPlainMultiSegmentTablePayload($series, $monthTotalsByKey);

                return new StatisticWidget(
                    StatisticWidgetType::Table,
                    $this->key().'_table',
                    $this->widgetPayloadNormalizer->normalize(
                        new TableWidgetPayload(
                            $plainTablePayload['headerTranslationKeys'],
                            $plainTablePayload['rows'],
                            array_diff_key(
                                $plainTablePayload,
                                ['headerTranslationKeys' => true, 'rows' => true]
                            ),
                        )
                    ),
                );
            }

            return StatisticsAnalysisDimension::Total === $dimension
                ? $this->buildTotalTableWidget($series, $n, $summaryStats)
                : $this->buildSegmentedTableWidget($series, $n, $summaryStats);
        }

        if ($this->usesPlainCountColumnsTable($dimension)) {
            $chartMeasureForSeries = $chartMeasure;
            if (StatisticsAnalysisDimension::Features === $dimension && StatisticsChartMeasure::Share === $chartMeasureForSeries) {
                $chartMeasureForSeries = StatisticsChartMeasure::Absolute;
            }

            return new StatisticWidget(
                StatisticWidgetType::SimpleChart,
                $this->key().'_chart',
                $this->widgetPayloadNormalizer->normalize(
                    $this->toSimpleChartPayload(
                        $this->buildMultiSeriesChartPayload($series, $chartType, $chartMeasureForSeries, $monthTotalsByKey)
                    )
                ),
            );
        }

        $type = 'line' === $chartType ? 'line' : 'bar';

        $payload = [
            'chartType' => $type,
            'labels' => $series->labels,
            'summaryStats' => $summaryStats,
        ];

        $singleTotal = 1 === \count($series->segments)
            && 'total' === $series->segments[0]->segmentKey;

        if ($singleTotal) {
            $payload['counts'] = $series->segments[0]->values;
        } else {
            /** @var list<array{name: string, data: list<int>}> $chartSeries */
            $chartSeries = [];
            foreach ($series->segments as $segment) {
                $chartSeries[] = [
                    'name' => $this->translator->trans($segment->labelTranslationKey),
                    'data' => $segment->values,
                ];
            }
            $payload['series'] = $chartSeries;
        }

        return new StatisticWidget(
            StatisticWidgetType::SimpleChart,
            $this->key().'_chart',
            $this->widgetPayloadNormalizer->normalize($this->toSimpleChartPayload($payload)),
        );
    }

    #[\Override]
    public function supportsDimensionSelector(): bool
    {
        return true;
    }

    #[\Override]
    public function supportsChartMeasureSelector(
        StatisticsAnalysisDimension $dimension,
        string $view,
        string $chartType,
    ): bool {
        return StatisticsAnalysisDimension::Resources === $dimension
            && 'chart' === $view
            && 'bar' === $chartType;
    }

    private function usesPlainCountColumnsTable(StatisticsAnalysisDimension $dimension): bool
    {
        return StatisticsAnalysisDimension::Resources === $dimension
            || StatisticsAnalysisDimension::Features === $dimension;
    }

    /**
     * Multi-series chart payload for analysis views.
     *
     * @param array<string, int> $monthTotalsByKey
     *
     * @return array<string, mixed>
     */
    private function buildMultiSeriesChartPayload(
        AllocationsOverTimeSeries $series,
        string $chartType,
        StatisticsChartMeasure $chartMeasure,
        array $monthTotalsByKey,
    ): array {
        $type = 'line' === $chartType ? 'line' : 'bar';

        if (StatisticsChartMeasure::Share === $chartMeasure && 'bar' === $type) {
            return $this->buildShareOfMonthlyTotalBarPayload($series, $monthTotalsByKey);
        }

        $totalsPerMonth = $series->totalsPerMonth();
        $summaryStats = $this->formatSummaryStats($totalsPerMonth);

        /** @var list<array{name: string, data: list<int>}> $chartSeries */
        $chartSeries = [];
        foreach ($series->segments as $segment) {
            $chartSeries[] = [
                'name' => $this->translator->trans($segment->labelTranslationKey),
                'data' => $segment->values,
            ];
        }

        $payload = [
            'chartType' => $type,
            'labels' => $series->labels,
            'summaryStats' => $summaryStats,
            'series' => $chartSeries,
        ];

        if ('bar' === $type) {
            $payload['barGrouped'] = true;
        }

        return $payload;
    }

    /**
     * @param array<string, int> $monthTotalsByKey
     *
     * @return array<string, mixed>
     */
    private function buildShareOfMonthlyTotalBarPayload(
        AllocationsOverTimeSeries $series,
        array $monthTotalsByKey,
    ): array {
        $totalsPerMonth = $series->totalsPerMonth();
        $summaryStats = $this->formatSummaryStats($totalsPerMonth);

        $n = \count($series->monthKeys);
        $overlapHint = false;

        /** @var list<float> $remainder */
        $remainder = [];
        $anyMonthTotal = false;
        for ($i = 0; $i < $n; ++$i) {
            $monthKey = $series->monthKeys[$i];
            $monthTotal = $monthTotalsByKey[$monthKey] ?? 0;
            if ($monthTotal > 0) {
                $anyMonthTotal = true;
            }
            $sumSeg = 0;
            foreach ($series->segments as $segment) {
                $sumSeg += $segment->values[$i];
            }
            if ($monthTotal > 0 && $sumSeg > $monthTotal) {
                $overlapHint = true;
            }
            $withAnyList = $series->countsAllocationsMatchingAtLeastOneSegment;
            if (null !== $withAnyList && \array_key_exists($i, $withAnyList)) {
                $matchAny = $withAnyList[$i];
                $restCount = $monthTotal > 0 ? max(0, $monthTotal - $matchAny) : 0;
            } else {
                $restCount = $monthTotal > 0 ? max(0, $monthTotal - $sumSeg) : 0;
            }
            $remainder[] = $this->percentOfMonthTotal($restCount, $monthTotal);
        }

        /** @var list<array{name: string, data: list<float>}> $chartSeries */
        $chartSeries = [];
        if ($anyMonthTotal) {
            $chartSeries[] = [
                'name' => $this->translator->trans('stats.analysis.chart.remainder_series'),
                'data' => $remainder,
            ];
        }

        foreach ($series->segments as $segment) {
            /** @var list<float> $data */
            $data = [];
            for ($i = 0; $i < $n; ++$i) {
                $monthKey = $series->monthKeys[$i];
                $monthTotal = $monthTotalsByKey[$monthKey] ?? 0;
                $data[] = $this->percentOfMonthTotal($segment->values[$i], $monthTotal);
            }
            $chartSeries[] = [
                'name' => $this->translator->trans($segment->labelTranslationKey),
                'data' => $data,
            ];
        }

        $payload = [
            'chartType' => 'bar',
            'labels' => $series->labels,
            'summaryStats' => $summaryStats,
            'series' => $chartSeries,
            'percentScale' => true,
        ];

        if ($overlapHint) {
            $payload['overlapNote'] = $this->translator->trans('stats.analysis.chart.overlap_hint');
        }

        return $payload;
    }

    /**
     * @return array<string, int>
     */
    private function monthTotalsByKeyMap(AllocationsOverTimeSeries $totalSeries): array
    {
        $map = [];
        foreach ($totalSeries->monthKeys as $i => $key) {
            $map[$key] = $totalSeries->segments[0]->values[$i] ?? 0;
        }

        return $map;
    }

    private function percentOfMonthTotal(int $count, int $monthTotal): float
    {
        if ($monthTotal <= 0) {
            return 0.0;
        }

        return round(100 * $count / $monthTotal, 2);
    }

    /**
     * Month table with integer counts per segment (no percent columns).
     *
     * @param array<string, int> $monthTotalsByKey
     *
     * @return array<string, mixed>
     */
    private function buildPlainMultiSegmentTablePayload(
        AllocationsOverTimeSeries $series,
        array $monthTotalsByKey,
    ): array {
        $n = \count($series->monthKeys);
        $totalsPerMonth = $series->totalsPerMonth();
        $summaryStats = $this->formatSummaryStats($totalsPerMonth);

        /** @var list<string> $headerTranslationKeys */
        $headerTranslationKeys = [
            'stats.analysis.table.month',
            'stats.analysis.table.allocations_month_total',
        ];
        foreach ($series->segments as $segment) {
            $headerTranslationKeys[] = $segment->labelTranslationKey;
        }

        $monthRowTargets = $this->monthRowTargetsForDimension($series);

        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $monthKey = $series->monthKeys[$i];
            $monthTotal = $monthTotalsByKey[$monthKey] ?? 0;
            $row = [$series->labels[$i], $monthTotal];
            foreach ($series->segments as $segment) {
                $row[] = $this->formatCountWithPercentDenominator($segment->values[$i], $monthTotal);
            }
            $rows[] = $row;
        }

        /** @var list<int> $footerSums */
        $footerSums = [];
        foreach ($series->segments as $segment) {
            $footerSums[] = array_sum($segment->values);
        }

        $grandMonthTotal = 0;
        foreach ($series->monthKeys as $monthKey) {
            $grandMonthTotal += $monthTotalsByKey[$monthKey] ?? 0;
        }

        /** @var list<string> $footerCounts */
        $footerCounts = [number_format($grandMonthTotal, 0, ',', '.')];
        foreach ($footerSums as $sum) {
            $footerCounts[] = $this->formatCountWithPercentDenominator($sum, $grandMonthTotal);
        }

        return [
            'headerTranslationKeys' => $headerTranslationKeys,
            'numericColumnStartIndex' => 2,
            'rows' => $rows,
            'monthRowTargets' => $monthRowTargets,
            'footerRow' => [
                'labelTranslationKey' => 'stats.analysis.table.total',
                'counts' => $footerCounts,
            ],
            'summaryStats' => $summaryStats,
        ];
    }

    /**
     * @return list<StatisticWidgetNavigationTarget|null>
     */
    private function monthRowTargetsForDimension(
        AllocationsOverTimeSeries $series,
    ): array {
        /** @var list<StatisticWidgetNavigationTarget|null> $targets */
        $targets = [];
        foreach ($series->monthKeys as $monthKey) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $matches)) {
                $targets[] = null;

                continue;
            }

            $targets[] = new StatisticWidgetNavigationTarget(
                '',
                'app_stats_analysis_explorer_view',
                [
                    'view' => $this->legacyViewMapper->slugForLegacyViewKey($this->key()),
                    'period' => StatisticsFilterPeriod::Month->value,
                    'year' => (int) $matches[1],
                    'month' => (int) $matches[2],
                ],
                ['report', 'limit', 'chart'],
            );
        }

        return $targets;
    }

    /**
     * @param array{meanDisplay: string, stdDevDisplay: string} $summaryStats
     */
    private function buildTotalTableWidget(
        AllocationsOverTimeSeries $series,
        int $n,
        array $summaryStats,
    ): StatisticWidget {
        $counts = $series->segments[0]->values;

        $total = 0;
        foreach ($counts as $c) {
            $total += $c;
        }

        $rows = [];
        $monthRowTargets = $this->monthRowTargetsForDimension($series);

        for ($i = 0; $i < $n; ++$i) {
            $c = $counts[$i];
            $pct = $total > 0 ? round(100 * $c / $total, 1) : 0.0;
            $rows[] = [
                $series->labels[$i],
                $c,
                sprintf('%.1f%%', $pct),
            ];
        }

        return new StatisticWidget(
            StatisticWidgetType::Table,
            $this->key().'_table',
            $this->widgetPayloadNormalizer->normalize(new TableWidgetPayload(
                [
                    'stats.analysis.table.month',
                    'stats.analysis.table.count',
                    'stats.analysis.table.share',
                ],
                $rows,
                [
                    'monthRowTargets' => $monthRowTargets,
                    'footerRow' => [
                        'labelTranslationKey' => 'stats.analysis.table.total',
                        'count' => $total,
                        'percentDisplay' => $total > 0 ? '100.0%' : '—',
                    ],
                    'summaryStats' => $summaryStats,
                ],
            )),
        );
    }

    /**
     * @param array{meanDisplay: string, stdDevDisplay: string} $summaryStats
     */
    private function buildSegmentedTableWidget(
        AllocationsOverTimeSeries $series,
        int $n,
        array $summaryStats,
    ): StatisticWidget {
        /** @var list<string> $headerTranslationKeys */
        $headerTranslationKeys = ['stats.analysis.table.month'];
        foreach ($series->segments as $segment) {
            $headerTranslationKeys[] = $segment->labelTranslationKey;
        }

        $rows = [];
        $monthRowTargets = $this->monthRowTargetsForDimension($series);

        for ($i = 0; $i < $n; ++$i) {
            $row = [$series->labels[$i]];
            $rowTotal = 0;
            foreach ($series->segments as $segment) {
                $rowTotal += $segment->values[$i];
            }
            foreach ($series->segments as $segment) {
                $row[] = $this->formatCountWithRowPercent($segment->values[$i], $rowTotal);
            }
            $rows[] = $row;
        }

        /** @var list<int> $footerSums */
        $footerSums = [];
        foreach ($series->segments as $segment) {
            $footerSums[] = array_sum($segment->values);
        }
        $grandTotal = array_sum($footerSums);
        /** @var list<string> $footerCounts */
        $footerCounts = [];
        foreach ($footerSums as $sum) {
            $footerCounts[] = $this->formatCountWithShareOfTotal($sum, $grandTotal);
        }

        return new StatisticWidget(
            StatisticWidgetType::Table,
            $this->key().'_table',
            $this->widgetPayloadNormalizer->normalize(new TableWidgetPayload(
                $headerTranslationKeys,
                $rows,
                [
                    'numericColumnStartIndex' => 2,
                    'monthRowTargets' => $monthRowTargets,
                    'footerRow' => [
                        'labelTranslationKey' => 'stats.analysis.table.total',
                        'counts' => $footerCounts,
                    ],
                    'summaryStats' => $summaryStats,
                ],
            )),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toSimpleChartPayload(array $payload): SimpleChartWidgetPayload
    {
        $chartType = \is_string($payload['chartType'] ?? null) ? $payload['chartType'] : 'bar';
        /** @var list<string> $labels */
        $labels = \is_array($payload['labels'] ?? null) ? $payload['labels'] : [];
        unset($payload['chartType'], $payload['labels']);

        return new SimpleChartWidgetPayload($chartType, $labels, $payload);
    }

    /** Row-relative share (segment cells in the row sum to 100%). */
    private function formatCountWithRowPercent(int $count, int $rowTotal): string
    {
        return $this->formatCountWithPercentDenominator($count, $rowTotal);
    }

    /** Share of each segment column total vs grand total across segments (footer). */
    private function formatCountWithShareOfTotal(int $count, int $grandTotal): string
    {
        return $this->formatCountWithPercentDenominator($count, $grandTotal);
    }

    private function formatCountWithPercentDenominator(int $count, int $denominator): string
    {
        if ($denominator <= 0) {
            return sprintf('%d (—)', $count);
        }

        $pct = round(100 * $count / $denominator, 1);

        return sprintf('%d (%.1f%%)', $count, $pct);
    }

    /**
     * @param list<int> $counts
     *
     * @return array{meanDisplay: string, stdDevDisplay: string}
     */
    private function formatSummaryStats(array $counts): array
    {
        $n = \count($counts);
        $total = 0;
        foreach ($counts as $c) {
            $total += $c;
        }

        $mean = $n > 0 ? $total / $n : 0.0;
        $stdDev = $this->sampleStandardDeviation($counts, $mean);

        return [
            'meanDisplay' => sprintf('%.2f', $mean),
            'stdDevDisplay' => sprintf('%.2f', $stdDev),
        ];
    }

    /**
     * Sample standard deviation (n − 1) of monthly values; 0 when fewer than two months.
     *
     * @param list<int> $counts
     */
    private function sampleStandardDeviation(array $counts, float $mean): float
    {
        $n = \count($counts);
        if ($n < 2) {
            return 0.0;
        }

        $sumSquaredDiff = 0.0;
        foreach ($counts as $value) {
            $diff = (float) $value - $mean;
            $sumSquaredDiff += $diff * $diff;
        }

        return sqrt($sumSquaredDiff / (float) ($n - 1));
    }
}
