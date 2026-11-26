<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\StatisticsPeriodResolver;
use Psr\Clock\ClockInterface;

/**
 * Resolves the comparison period of the overview self-benchmark (KPI tiles and insights, #1071).
 *
 * - last 12 months: the 12 months before, over the same span as the rolling window so far;
 * - year, quarter, month: the previous year, quarter or month; while the period is running, only the same span
 *   from the start of the previous period;
 * - all time: no comparison, there is no period before the whole history.
 */
final readonly class OverviewComparisonPeriodResolver
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function resolve(StatisticsFilter $filter): ?OverviewComparisonPeriod
    {
        $step = match ($filter->period) {
            StatisticsFilterPeriod::Last12Months, StatisticsFilterPeriod::Year => '-1 year',
            StatisticsFilterPeriod::Quarter => '-3 months',
            StatisticsFilterPeriod::Month => '-1 month',
            StatisticsFilterPeriod::AllTime => null,
        };
        if (null === $step) {
            return null;
        }

        $bounds = StatisticsPeriodResolver::resolve($filter);
        $from = $bounds->from;
        if (!$from instanceof \DateTimeImmutable) {
            return null;
        }

        // Period bounds use the default time zone; compare and measure the span in the same zone.
        $now = $this->clock->now()->setTimezone($from->getTimezone());
        if ($from >= $now) {
            return null;
        }

        $comparisonFrom = $from->modify($step);
        $isRunning = !$bounds->toExclusive instanceof \DateTimeImmutable || $bounds->toExclusive > $now;

        if (!$isRunning) {
            return new OverviewComparisonPeriod(
                $bounds,
                new StatisticsPeriodBounds($comparisonFrom, $from),
                $this->kind($filter->period, false),
            );
        }

        // Same calendar span as the running period so far (months, days, time of day; a DST change in between does
        // not shift it), never reaching into the primary period.
        $comparisonTo = $comparisonFrom->add($from->diff($now));
        if ($comparisonTo > $from) {
            $comparisonTo = $from;
        }

        return new OverviewComparisonPeriod(
            new StatisticsPeriodBounds($from, $now),
            new StatisticsPeriodBounds($comparisonFrom, $comparisonTo),
            $this->kind($filter->period, true),
        );
    }

    private function kind(StatisticsFilterPeriod $period, bool $isRunning): OverviewComparisonKind
    {
        return match ($period) {
            StatisticsFilterPeriod::Year => $isRunning ? OverviewComparisonKind::YearToDate : OverviewComparisonKind::Year,
            StatisticsFilterPeriod::Quarter => $isRunning ? OverviewComparisonKind::QuarterToDate : OverviewComparisonKind::Quarter,
            StatisticsFilterPeriod::Month => $isRunning ? OverviewComparisonKind::MonthToDate : OverviewComparisonKind::Month,
            default => OverviewComparisonKind::Last12Months,
        };
    }
}
