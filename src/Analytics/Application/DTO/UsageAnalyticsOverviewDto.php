<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsOverviewDto
{
    /**
     * @param list<array{featureArea: string, requestCount: int, sharePercent: float}> $featureAreas
     * @param list<array{routeName: string, requestCount: int}>                        $topRoutes
     * @param array{authenticated: int, anonymous: int}                                $authenticationSplit
     * @param array{dau: int, wau: int, mau: int, sessionsLast7Days: int}              $retention
     */
    public function __construct(
        public int $requestsToday,
        public int $requestsLast7Days,
        public int $requestsLast30Days,
        public array $featureAreas,
        public array $topRoutes,
        public array $authenticationSplit,
        public array $retention,
    ) {
    }
}
