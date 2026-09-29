<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsResult;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;

final readonly class ClosureAnalyticsService
{
    private const array BREAKDOWN_KINDS = [
        'hospital',
        'speciality',
        'department',
        'care_level',
        'reason',
        'closure_unit',
    ];

    public function __construct(
        private ClosureTemporalQuery $temporalQuery,
        private ClosureEventQuery $eventQuery,
    ) {
    }

    public function buildEvents(ClosureAnalyticsCriteria $criteria, int $page = 1): ClosureAnalyticsResult
    {
        $events = $this->eventQuery->fetchEvents($criteria, $page);

        return new ClosureAnalyticsResult(
            $this->temporalQuery->fetchMetrics($criteria),
            [],
            [],
            $events['rows'],
            [],
            $events['total'],
            $events['page'],
            max(1, (int) ceil($events['total'] / 25)),
        );
    }

    public function buildOverview(ClosureAnalyticsCriteria $criteria): ClosureAnalyticsResult
    {
        $breakdowns = [];
        foreach (self::BREAKDOWN_KINDS as $kind) {
            $breakdowns[$kind] = $this->temporalQuery->fetchBreakdown($criteria, $kind);
        }

        return new ClosureAnalyticsResult(
            $this->temporalQuery->fetchMetrics($criteria),
            $this->temporalQuery->fetchTimeSeries($criteria),
            $breakdowns,
            [],
            $this->temporalQuery->fetchHeatmap($criteria),
            0,
            1,
            1,
        );
    }
}
