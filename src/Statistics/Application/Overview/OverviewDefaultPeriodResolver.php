<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\StatisticsPeriod;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

final readonly class OverviewDefaultPeriodResolver
{
    private const int MIN_MONTHS_WITH_DATA = 7;

    public function __construct(
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    public function resolveDefaultPeriod(StatisticsContext $context): StatisticsFilterPeriod
    {
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);
        if (\is_array($scopeCriteria->hospitalIds) && [] === $scopeCriteria->hospitalIds) {
            return StatisticsFilterPeriod::AllTime;
        }

        $monthsWithData = 0;
        foreach ($this->timeSeriesQuery->countByMonthInPeriod(
            StatisticsPeriod::overviewPeriodStart(),
            null,
            $scopeCriteria->hospitalIds,
            $scopeCriteria->dispatchAreaId,
        ) as $row) {
            if ($row['count'] > 0) {
                ++$monthsWithData;
            }
        }

        return $monthsWithData >= self::MIN_MONTHS_WITH_DATA
            ? StatisticsFilterPeriod::All
            : StatisticsFilterPeriod::AllTime;
    }
}
