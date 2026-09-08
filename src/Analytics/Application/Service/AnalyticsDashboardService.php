<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\UsageAnalyticsAdoptionDto;
use App\Analytics\Application\DTO\UsageAnalyticsFiltersDto;
use App\Analytics\Application\DTO\UsageAnalyticsJourneysDto;
use App\Analytics\Application\DTO\UsageAnalyticsOverviewDto;
use App\Analytics\Application\DTO\UsageAnalyticsPerformanceDto;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\Query\AnalyticsReportingQuery;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;

final readonly class AnalyticsDashboardService
{
    private const array DEPTH_LABELS = [
        0 => 'Registered / low engagement',
        1 => 'Dashboard viewed',
        2 => 'Statistics / Explore / Import',
        3 => 'Analysis run',
        4 => 'Filters used',
        5 => 'Export created',
    ];

    private const array FUNNEL_STEPS = [
        UsageEventName::USER_REGISTERED,
        UsageEventName::USER_EMAIL_CONFIRMED,
        UsageEventName::USER_BECAME_PARTICIPANT,
        UsageEventName::IMPORT_COMPLETED,
        UsageEventName::ANALYSIS_EXPLORER_RUN,
        UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV,
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsRequestRepository $requestRepository,
        private AnalyticsProductEventRepository $eventRepository,
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

    public function getAdoption(): UsageAnalyticsAdoptionDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);
        $from30 = AnalyticsCalendar::daysAgo(29);

        return new UsageAnalyticsAdoptionDto(
            topEvents: $this->reportingQuery->topEventsSince($from7),
            eventsByRole: $this->reportingQuery->eventsByRoleSince($from7),
            roleAreaMatrix: $this->reportingQuery->roleAreaMatrixSince($from7),
            engagementDepth: $this->buildEngagementDepth($from30),
        );
    }

    public function getJourneys(): UsageAnalyticsJourneysDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);
        $from30 = AnalyticsCalendar::daysAgo(29);

        return new UsageAnalyticsJourneysDto(
            onboardingFunnel: $this->eventRepository->onboardingFunnelSince($from30, self::FUNNEL_STEPS),
            entryRoutes: $this->requestRepository->topEntryRoutesSince($from7),
            exitRoutes: $this->requestRepository->topExitRoutesSince($from7),
            transitions: $this->requestRepository->topTransitionsSince($from7),
            timeToFirst: $this->eventRepository->timeToFirstMetrics($from30),
        );
    }

    public function getFilters(): UsageAnalyticsFiltersDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);

        return new UsageAnalyticsFiltersDto(
            topFilterParams: $this->reportingQuery->topFilterParamsSince($from7),
            filterUsageByArea: $this->reportingQuery->filterUsageByAreaSince($from7),
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

    /**
     * @return list<array{level: int, label: string, userCount: int}>
     */
    private function buildEngagementDepth(\DateTimeImmutable $from): array
    {
        $levels = $this->eventRepository->maxEventLevelsByUserSince($from);
        foreach ($this->requestRepository->maxRequestLevelsByUserSince($from) as $key => $level) {
            $levels[$key] = max($levels[$key] ?? 0, $level);
        }

        $counts = array_fill_keys(array_keys(self::DEPTH_LABELS), 0);
        foreach ($levels as $level) {
            $clamped = max(0, min(5, $level));
            ++$counts[$clamped];
        }

        $result = [];
        foreach (self::DEPTH_LABELS as $level => $label) {
            $result[] = [
                'level' => $level,
                'label' => $label,
                'userCount' => $counts[$level],
            ];
        }

        return $result;
    }
}
