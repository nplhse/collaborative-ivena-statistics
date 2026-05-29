<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;

/**
 * Resolves the evaluation time window for allocations/imports from {@see StatisticsFilter}.
 *
 * period=all matches the overview: rolling 12 months from {@see StatisticsPeriod::overviewPeriodStart()},
 * with no explicit upper bound (only createdAt >= from).
 *
 * period=all_time: no created_at lower bound in queries; callers use earliest row when a start label is needed.
 */
final class StatisticsPeriodResolver
{
    public static function resolve(StatisticsFilter $filter): StatisticsPeriodBounds
    {
        $now = new \DateTimeImmutable('now');

        return match ($filter->period) {
            StatisticsFilterPeriod::All => new StatisticsPeriodBounds(
                StatisticsPeriod::overviewPeriodStart(),
            ),
            StatisticsFilterPeriod::AllTime => new StatisticsPeriodBounds(null),
            StatisticsFilterPeriod::Year => self::resolveYear($filter, $now),
            StatisticsFilterPeriod::Quarter => self::resolveQuarter($filter, $now),
            StatisticsFilterPeriod::Month => self::resolveMonth($filter, $now),
        };
    }

    private static function resolveYear(StatisticsFilter $filter, \DateTimeImmutable $now): StatisticsPeriodBounds
    {
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $from = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $toExclusive = $from->modify('+1 year');

        return new StatisticsPeriodBounds($from, $toExclusive);
    }

    private static function resolveQuarter(StatisticsFilter $filter, \DateTimeImmutable $now): StatisticsPeriodBounds
    {
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $quarter = $filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3);
        $quarter = max(1, min(4, $quarter));
        $startMonth = ($quarter - 1) * 3 + 1;
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $startMonth));
        $toExclusive = $from->modify('+3 months');

        return new StatisticsPeriodBounds($from, $toExclusive);
    }

    private static function resolveMonth(StatisticsFilter $filter, \DateTimeImmutable $now): StatisticsPeriodBounds
    {
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $month = $filter->referenceMonth ?? (int) $now->format('n');
        $month = max(1, min(12, $month));

        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $toExclusive = $from->modify('+1 month');

        return new StatisticsPeriodBounds($from, $toExclusive);
    }
}
