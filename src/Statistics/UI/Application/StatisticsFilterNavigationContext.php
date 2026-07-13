<?php

declare(strict_types=1);

namespace App\Statistics\UI\Application;

use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;

final readonly class StatisticsFilterNavigationContext
{
    /**
     * @param list<string> $removeScopeDependent
     * @param list<string> $removePeriodDependent
     * @param list<string> $removeMonthDependent
     */
    public function __construct(
        public string $scopeQueryKey,
        public string $hospitalQueryKey,
        public string $periodQueryKey,
        public string $yearQueryKey,
        public string $monthQueryKey,
        public string $quarterQueryKey,
        public array $removeScopeDependent,
        public array $removePeriodDependent,
        public array $removeMonthDependent,
        public StatisticsScopeViewModelVariant $variant,
    ) {
    }

    public static function forStatistics(): self
    {
        return new self(
            scopeQueryKey: StatisticsQueryKeys::SCOPE,
            hospitalQueryKey: StatisticsQueryKeys::HOSPITAL,
            periodQueryKey: StatisticsQueryKeys::PERIOD,
            yearQueryKey: StatisticsQueryKeys::YEAR,
            monthQueryKey: StatisticsQueryKeys::MONTH,
            quarterQueryKey: StatisticsQueryKeys::QUARTER,
            removeScopeDependent: StatisticsQueryKeys::REMOVE_SCOPE_DEPENDENT,
            removePeriodDependent: StatisticsQueryKeys::REMOVE_PERIOD_DEPENDENT,
            removeMonthDependent: StatisticsQueryKeys::REMOVE_MONTH_DEPENDENT,
            variant: StatisticsScopeViewModelVariant::Statistics,
        );
    }

    public static function forBenchmarking(): self
    {
        return new self(
            scopeQueryKey: StatisticsQueryKeys::COMPARISON_SCOPE,
            hospitalQueryKey: StatisticsQueryKeys::COMPARISON_HOSPITAL,
            periodQueryKey: StatisticsQueryKeys::COMPARISON_PERIOD,
            yearQueryKey: StatisticsQueryKeys::COMPARISON_YEAR,
            monthQueryKey: StatisticsQueryKeys::COMPARISON_MONTH,
            quarterQueryKey: StatisticsQueryKeys::COMPARISON_QUARTER,
            removeScopeDependent: StatisticsQueryKeys::REMOVE_COMPARISON_SCOPE_DEPENDENT,
            removePeriodDependent: StatisticsQueryKeys::REMOVE_COMPARISON_PERIOD_DEPENDENT,
            removeMonthDependent: StatisticsQueryKeys::REMOVE_COMPARISON_MONTH_DEPENDENT,
            variant: StatisticsScopeViewModelVariant::Benchmarking,
        );
    }
}
