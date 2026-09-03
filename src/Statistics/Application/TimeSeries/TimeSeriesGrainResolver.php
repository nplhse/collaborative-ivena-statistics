<?php

declare(strict_types=1);

namespace App\Statistics\Application\TimeSeries;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;

/**
 * Maps a selected filter period to the automatic time-series bucket size.
 *
 * Period remains the query window; this grain is only the chart/table aggregation.
 */
final class TimeSeriesGrainResolver
{
    public static function resolve(StatisticsFilterPeriod $period): TimeSeriesGrain
    {
        return match ($period) {
            StatisticsFilterPeriod::Month => TimeSeriesGrain::Day,
            StatisticsFilterPeriod::Year,
            StatisticsFilterPeriod::Quarter,
            StatisticsFilterPeriod::All,
            StatisticsFilterPeriod::AllTime => TimeSeriesGrain::Month,
        };
    }

    /**
     * Refines an explicit time-axis grain when it would collapse to a single period-sized bucket.
     *
     * Finer grains (e.g. week within a year) are left unchanged. Open periods (`all`, `all_time`)
     * never clamp, so views such as allocations-by-year keep yearly buckets.
     */
    public static function clampGrainValue(StatisticsFilterPeriod $period, string $grain): string
    {
        return match ($period) {
            StatisticsFilterPeriod::Month => match ($grain) {
                'month', 'quarter', 'year' => TimeSeriesGrain::Day->value,
                default => $grain,
            },
            StatisticsFilterPeriod::Quarter => match ($grain) {
                'quarter', 'year' => TimeSeriesGrain::Month->value,
                default => $grain,
            },
            StatisticsFilterPeriod::Year => 'year' === $grain ? TimeSeriesGrain::Month->value : $grain,
            StatisticsFilterPeriod::All,
            StatisticsFilterPeriod::AllTime => $grain,
        };
    }
}
