<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsPerformanceDto
{
    /**
     * @param list<array{featureArea: string, requestCount: int, avgDurationMs: float, p95DurationMs: float, avgQueries: float, errorRatePercent: float}> $performanceByArea
     * @param list<array{routeName: string, requestCount: int, avgDurationMs: float}>                                                                     $slowestRoutes
     * @param list<string>                                                                                                                                $performanceInsights
     */
    public function __construct(
        public array $performanceByArea,
        public array $slowestRoutes,
        public array $performanceInsights,
    ) {
    }
}
