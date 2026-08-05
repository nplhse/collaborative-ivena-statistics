<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\UsageAnalyticsAdoptionDto;
use App\Analytics\Application\DTO\UsageAnalyticsFiltersDto;
use App\Analytics\Application\DTO\UsageAnalyticsJourneysDto;
use App\Analytics\Application\DTO\UsageAnalyticsOverviewDto;
use App\Analytics\Application\DTO\UsageAnalyticsPerformanceDto;
use App\Analytics\Domain\UsageEventName;
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
    ) {
    }

    public function getOverview(): UsageAnalyticsOverviewDto
    {
        $from7 = $this->requestRepository->daysAgo(6);

        return new UsageAnalyticsOverviewDto(
            requestsToday: $this->requestRepository->countToday(),
            requestsLast7Days: $this->requestRepository->countLast7Days(),
            requestsLast30Days: $this->requestRepository->countLast30Days(),
            featureAreas: $this->requestRepository->featureAreaDistributionSince($from7),
            topRoutes: $this->requestRepository->topRoutesSince($from7),
            authenticationSplit: $this->requestRepository->authenticationSplitSince($from7),
            retention: $this->requestRepository->retentionSnapshot(),
        );
    }

    public function getAdoption(): UsageAnalyticsAdoptionDto
    {
        $from7 = $this->requestRepository->daysAgo(6);
        $from30 = $this->requestRepository->daysAgo(29);

        return new UsageAnalyticsAdoptionDto(
            topEvents: $this->eventRepository->topEventsSince($from7),
            eventsByRole: $this->eventRepository->eventsByRoleSince($from7),
            roleAreaMatrix: $this->requestRepository->roleAreaMatrixSince($from7),
            engagementDepth: $this->buildEngagementDepth($from30),
        );
    }

    public function getJourneys(): UsageAnalyticsJourneysDto
    {
        $from7 = $this->requestRepository->daysAgo(6);
        $from30 = $this->requestRepository->daysAgo(29);

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
        $from7 = $this->requestRepository->daysAgo(6);

        return new UsageAnalyticsFiltersDto(
            topFilterParams: $this->requestRepository->topFilterParamsSince($from7),
            filterUsageByArea: $this->requestRepository->filterUsageByAreaSince($from7),
        );
    }

    public function getPerformance(): UsageAnalyticsPerformanceDto
    {
        $from7 = $this->requestRepository->daysAgo(6);
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
