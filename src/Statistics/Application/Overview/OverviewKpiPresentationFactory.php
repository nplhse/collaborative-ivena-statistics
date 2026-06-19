<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class OverviewKpiPresentationFactory
{
    /**
     * @return array<string, string>
     */
    public function metricLabelKeys(StatisticsFilter $filter): array
    {
        $casesPerDayKey = OverviewScopeClassifier::isAggregateScope($filter->scope)
            ? 'stats.overview.kpi.cases_per_day_aggregate'
            : 'stats.overview.kpi.cases_per_day';

        return [
            'cases_per_day' => $casesPerDayKey,
        ];
    }

    public function casesPerDayHintTranslationKey(StatisticsFilter $filter): ?string
    {
        return OverviewScopeClassifier::isAggregateScope($filter->scope)
            ? 'stats.overview.kpi.cases_per_day_aggregate_hint'
            : null;
    }
}
