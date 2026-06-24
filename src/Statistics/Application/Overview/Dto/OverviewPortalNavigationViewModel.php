<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;

final readonly class OverviewPortalNavigationViewModel
{
    /**
     * @param list<StatisticWidgetNavigationTarget> $timeSeries
     * @param list<StatisticWidgetNavigationTarget> $heatmapHour
     * @param list<StatisticWidgetNavigationTarget> $heatmapWeekday
     * @param list<StatisticWidgetNavigationTarget> $ageGroups
     * @param list<StatisticWidgetNavigationTarget> $transportTime
     */
    public function __construct(
        public array $timeSeries,
        public array $heatmapHour,
        public array $heatmapWeekday,
        public array $ageGroups,
        public array $transportTime,
    ) {
    }
}
