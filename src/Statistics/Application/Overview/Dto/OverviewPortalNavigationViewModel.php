<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;

final readonly class OverviewPortalNavigationViewModel
{
    /**
     * @param list<StatisticWidgetNavigationTarget> $timeSeries
     * @param list<StatisticWidgetNavigationTarget> $heatmapDayTime
     * @param list<StatisticWidgetNavigationTarget> $heatmapShift
     * @param list<StatisticWidgetNavigationTarget> $ageGroups
     */
    public function __construct(
        public array $timeSeries,
        public array $heatmapDayTime,
        public array $heatmapShift,
        public array $ageGroups,
    ) {
    }
}
