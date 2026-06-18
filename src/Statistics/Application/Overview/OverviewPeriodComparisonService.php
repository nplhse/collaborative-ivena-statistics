<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\StatisticsPeriodNavigation;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

final readonly class OverviewPeriodComparisonService
{
    public function __construct(
        private StatisticsPeriodNavigation $periodNavigation,
        private StatisticsScopeResolver $scopeResolver,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private ProjectionEarliestDateProviderInterface $earliestDateProvider,
    ) {
    }

    public function supportsPop(StatisticsFilter $filter): bool
    {
        return $this->periodNavigation->previous($filter) instanceof StatisticsFilter;
    }

    public function fetchPreviousScopedTotal(StatisticsContext $context): ?int
    {
        $previousFilter = $this->periodNavigation->previous($context->filter);
        if (!$previousFilter instanceof StatisticsFilter) {
            return null;
        }

        $previousContext = new StatisticsContext($context->user, $previousFilter);
        $bounds = StatisticsPeriodResolver::resolve($previousFilter);
        $hospitalIds = $this->scopeResolver->resolveCriteria($previousContext)->hospitalIds;

        return $this->timeSeriesQuery->countCreatedInPeriod(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
        );
    }

    public static function relativePercentChange(float|int|null $current, float|int|null $previous): ?float
    {
        if (null === $current || null === $previous) {
            return null;
        }

        if (0.0 === (float) $previous) {
            return 0.0 === (float) $current ? 0.0 : null;
        }

        return round(100.0 * (((float) $current - (float) $previous) / (float) $previous), 1);
    }

    public static function relativeRatePointChange(float $currentRate, float $previousRate): float
    {
        return round($currentRate - $previousRate, 1);
    }

    public function periodDayCount(StatisticsFilter $filter): ?int
    {
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $fallbackFrom = null;
        if (!$bounds->from instanceof \DateTimeImmutable) {
            $fallbackFrom = $this->earliestDateProvider->getEarliestCreatedAt();
        }

        return self::boundsDayCount($bounds, $fallbackFrom);
    }

    public static function boundsDayCount(
        StatisticsPeriodBounds $bounds,
        ?\DateTimeImmutable $fallbackFrom = null,
    ): ?int {
        $from = $bounds->from ?? $fallbackFrom;
        if (!$from instanceof \DateTimeImmutable) {
            return null;
        }

        $end = $bounds->toExclusive ?? new \DateTimeImmutable('now');

        return max(1, (int) $from->diff($end)->days);
    }

    public static function isPopComparablePeriod(StatisticsFilterPeriod $period): bool
    {
        return match ($period) {
            StatisticsFilterPeriod::Month,
            StatisticsFilterPeriod::Quarter,
            StatisticsFilterPeriod::Year => true,
            default => false,
        };
    }
}
