<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\Overview\Dto\OverviewIndicationRow;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodNavigation;
use App\Statistics\Application\TopDiagnosesQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewIndicationSectionFactory
{
    private const int SIDEBAR_TOP_LIMIT = 10;

    private const int DONUT_TOP_LIMIT = 5;

    private const float TREND_THRESHOLD = 5.0;

    public function __construct(
        private TopDiagnosesQuery $topDiagnosesQuery,
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsPeriodNavigation $periodNavigation,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    /**
     * @return array{
     *     rows: list<OverviewIndicationRow>,
     *     donut: array{labels: list<string>, values: list<int>},
     *     insightsUrl: string
     * }
     */
    public function build(Request $request, StatisticsContext $context, int $scopedTotal): array
    {
        $current = $this->topDiagnosesQuery->fetch($context, self::SIDEBAR_TOP_LIMIT, $scopedTotal);
        $previousCounts = $this->fetchPreviousCounts($context);

        $rows = [];
        $rank = 1;
        $donutLabels = [];
        $donutValues = [];

        foreach ($current['rows'] as $row) {
            $count = $row['count'];
            $total = $current['totalAllocations'];
            $share = $total > 0 ? round(100 * $count / $total, 1) : 0.0;
            $previousCount = null;
            if (null !== $row['indicationId']) {
                $previousCount = $previousCounts[$row['indicationId']] ?? 0;
            }

            [$trendDisplay, $trendTone] = $this->formatTrend($count, $previousCount);

            $rows[] = new OverviewIndicationRow(
                $rank,
                $row['label'],
                $count,
                sprintf('%.1f%%', $share),
                $trendDisplay,
                $trendTone,
                $row['indicationId'] ?? null,
                isset($row['indicationId'])
                    ? $this->navigationUrlBuilder->build(
                        $request,
                        'app_stats_indication_dashboard',
                        ['indicationId' => $row['indicationId']],
                    )
                    : null,
            );

            if ($rank <= self::DONUT_TOP_LIMIT) {
                $donutLabels[] = $row['label'];
                $donutValues[] = $count;
            }

            ++$rank;
        }

        $otherCount = max(0, $current['totalAllocations'] - array_sum($donutValues));
        if ($otherCount > 0) {
            $donutLabels[] = 'other';
            $donutValues[] = $otherCount;
        }

        return [
            'rows' => $rows,
            'donut' => [
                'labels' => $donutLabels,
                'values' => $donutValues,
            ],
            'insightsUrl' => $this->navigationUrlBuilder->build($request, 'app_stats_indication_insights'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function fetchPreviousCounts(StatisticsContext $context): array
    {
        $previousFilter = $this->periodNavigation->previous($context->filter);
        if (!$previousFilter instanceof \App\Statistics\Application\DTO\StatisticsFilter) {
            return [];
        }

        $previousContext = $this->statisticsContextFactory->create($context->user, $previousFilter);
        $data = $this->topDiagnosesQuery->fetch($previousContext, self::SIDEBAR_TOP_LIMIT);
        $counts = [];
        foreach ($data['rows'] as $row) {
            if (isset($row['indicationId'])) {
                $counts[$row['indicationId']] = $row['count'];
            }
        }

        return $counts;
    }

    /**
     * @return array{?string, ?string}
     */
    private function formatTrend(int $current, ?int $previous): array
    {
        if (null === $previous) {
            return [null, null];
        }

        if (0 === $previous) {
            return $current > 0 ? ['+100%', 'success'] : ['—', null];
        }

        $delta = round(100 * ($current - $previous) / $previous, 1);
        if (abs($delta) < self::TREND_THRESHOLD) {
            return ['—', null];
        }

        $tone = $delta > 0 ? 'success' : 'danger';

        return [($delta >= 0 ? '+' : '').number_format($delta, 1, ',', '.').'%', $tone];
    }
}
