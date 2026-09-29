<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureAnalyticsResult
{
    /**
     * @param list<ClosureTimeBucket>                  $timeSeries
     * @param array<string, list<ClosureBreakdownRow>> $breakdowns
     * @param list<ClosureEventRow>                    $events
     * @param list<ClosureHeatmapCell>                 $heatmap
     */
    public function __construct(
        public ClosureTimeMetrics $metrics,
        public array $timeSeries,
        public array $breakdowns,
        public array $events,
        public array $heatmap,
        public int $eventTotal,
        public int $page,
        public int $pages,
    ) {
    }

    public function hasIntervals(): bool
    {
        return $this->metrics->closureCount > 0;
    }
}
