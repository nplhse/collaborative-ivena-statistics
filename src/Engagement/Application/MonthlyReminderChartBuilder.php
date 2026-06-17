<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Application\Dto\MonthlyReminderChartBar;
use App\Statistics\Application\ChartBucketMapper;

final readonly class MonthlyReminderChartBuilder
{
    private const int MAX_BAR_HEIGHT_PX = 80;

    public function __construct(
        private ChartBucketMapper $chartBucketMapper,
    ) {
    }

    /**
     * @param list<string>                              $monthKeys
     * @param list<string>                              $labels
     * @param list<array{year:int,month:int,count:int}> $allocationRows
     *
     * @return list<MonthlyReminderChartBar>
     */
    public function build(
        array $monthKeys,
        array $labels,
        array $allocationRows,
        string $reportingMonthKey,
    ): array {
        $allocationCounts = $this->chartBucketMapper->mapMonthlyCounts(
            $monthKeys,
            $this->chartBucketMapper->monthRowsToBucketCounts($allocationRows),
        );

        $maxValue = max(1, ...$allocationCounts);

        $bars = [];
        foreach ($monthKeys as $index => $monthKey) {
            $allocation = $allocationCounts[$index] ?? 0;
            $bars[] = new MonthlyReminderChartBar(
                $labels[$index] ?? $monthKey,
                $allocation,
                (int) round((float) $allocation / (float) $maxValue * (float) self::MAX_BAR_HEIGHT_PX),
                $monthKey === $reportingMonthKey,
            );
        }

        return $bars;
    }

    /**
     * @param list<int> $values last N monthly counts
     */
    public function summarizeTrend(array $values): string
    {
        if (\count($values) < 2) {
            return '';
        }

        $recent = \array_slice($values, -6);
        if (\count($recent) < 2) {
            return '';
        }

        $first = (float) $recent[0];
        $last = (float) $recent[\count($recent) - 1];
        if ($first <= 0.0) {
            if ($last > 0.0) {
                return 'monthly_reminder.trend.growing_from_zero';
            }

            return 'monthly_reminder.trend.stable';
        }

        $totalChange = (($last - $first) / $first) * 100.0;
        $months = \count($recent) - 1;
        $avgMonthly = $totalChange / (float) max(1, $months);

        if ($avgMonthly >= 2.0) {
            return 'monthly_reminder.trend.growing';
        }
        if ($avgMonthly <= -2.0) {
            return 'monthly_reminder.trend.declining';
        }

        return 'monthly_reminder.trend.stable';
    }

    public function percentChange(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(((float) ($current - $previous) / (float) $previous) * 100.0, 1);
    }
}
