<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\UsageAnalyticsOverviewDto;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Infrastructure\Query\AnalyticsReportingQuery;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;

final readonly class AnalyticsDashboardService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsRequestRepository $requestRepository,
        private AnalyticsReportingQuery $reportingQuery,
    ) {
    }

    public function getOverview(): UsageAnalyticsOverviewDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);

        return new UsageAnalyticsOverviewDto(
            requestsToday: $this->reportingQuery->countSince(AnalyticsCalendar::startOfToday()),
            requestsLast7Days: $this->reportingQuery->countSince($from7),
            requestsLast30Days: $this->reportingQuery->countSince(AnalyticsCalendar::daysAgo(29)),
            featureAreas: $this->reportingQuery->featureAreaDistributionSince($from7),
            topRoutes: $this->reportingQuery->topRoutesSince($from7),
            authenticationSplit: $this->reportingQuery->authenticationSplitSince($from7),
            retention: $this->requestRepository->retentionSnapshot(),
        );
    }

    /**
     * Distinct users today (DAU), in the last 7 days (WAU) and in the last 30 days (MAU), Berlin calendar days
     * including today; the same numbers as on the usage analytics overview.
     *
     * @return array{dau: int, wau: int, mau: int}
     */
    public function getActiveUsers(): array
    {
        $retention = $this->requestRepository->retentionSnapshot();

        return ['dau' => $retention['dau'], 'wau' => $retention['wau'], 'mau' => $retention['mau']];
    }
}
