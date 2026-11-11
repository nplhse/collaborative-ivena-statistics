<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\UsageAnalyticsOverviewDto;
use App\Analytics\Application\DTO\UsageAnalyticsPerformanceDto;
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

    public function getPerformance(): UsageAnalyticsPerformanceDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);
        $perfByArea = $this->requestRepository->performanceByAreaSince($from7);
        $insightsInput = array_map(
            static fn (array $row): array => [
                'featureArea' => $row['featureArea'],
                'requestCount' => $row['requestCount'],
                'avgDurationMs' => $row['avgDurationMs'],
                'avgQueries' => $row['avgQueries'],
            ],
            $perfByArea,
        );

        return new UsageAnalyticsPerformanceDto(
            performanceByArea: $perfByArea,
            slowestRoutes: $this->requestRepository->slowestRoutesSince($from7),
            performanceInsights: $this->requestRepository->buildPerformanceInsights($insightsInput),
        );
    }
}
