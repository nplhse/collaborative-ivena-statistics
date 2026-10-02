<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsResult;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;

final readonly class ClosureAnalyticsService
{
    private const array BREAKDOWN_KINDS = [
        'event_type',
        'hospital',
        'speciality',
        'department',
        'care_level',
        'reason',
        'closure_unit',
    ];

    public function __construct(
        private ClosureTemporalQuery $temporalQuery,
    ) {
    }

    public function buildOverview(ClosureAnalyticsCriteria $criteria): ClosureAnalyticsResult
    {
        $breakdowns = [];
        foreach (self::BREAKDOWN_KINDS as $kind) {
            $breakdowns[$kind] = $this->temporalQuery->fetchBreakdown($criteria, $kind);
        }

        return new ClosureAnalyticsResult(
            $this->temporalQuery->fetchMetrics($criteria),
            ClosureDevelopmentSeries::complete($this->temporalQuery->fetchTimeSeries($criteria), $criteria),
            $breakdowns,
            [],
            $this->temporalQuery->fetchHeatmap($criteria),
            0,
            1,
            1,
        );
    }
}
