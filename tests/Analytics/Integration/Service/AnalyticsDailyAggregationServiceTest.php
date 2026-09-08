<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\Service\AnalyticsDailyAggregationService;
use App\Analytics\Application\Service\AnalyticsRawRetentionService;
use App\Analytics\Application\Service\AnalyticsScheduledAggregationService;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\Query\AnalyticsReportingQuery;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;

final class AnalyticsDailyAggregationServiceTest extends DatabaseKernelTestCase
{
    private AnalyticsDailyAggregationService $aggregationService;

    private AnalyticsRawRetentionService $retentionService;

    private AnalyticsReportingQuery $reportingQuery;

    private AnalyticsRequestRepository $requestRepository;

    private AnalyticsProductEventRepository $eventRepository;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->aggregationService = self::getContainer()->get(AnalyticsDailyAggregationService::class);
        $this->retentionService = self::getContainer()->get(AnalyticsRawRetentionService::class);
        $this->reportingQuery = self::getContainer()->get(AnalyticsReportingQuery::class);
        $this->requestRepository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $this->eventRepository = self::getContainer()->get(AnalyticsProductEventRepository::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAggregateForDateIsIdempotentAndWritesMarkerForEmptyDays(): void
    {
        $day = AnalyticsCalendar::daysAgo(2)->setTime(12, 0);
        $this->saveRequest($day, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-a', 'session-a', ['period']);
        $this->saveRequest($day->modify('+1 hour'), 'app_stats_analysis_explorer', FeatureArea::Analysis, true, 'ROLE_PARTICIPANT', 'user-a', 'session-a', []);
        $this->eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::ANALYSIS_EXPLORER_RUN,
            featureArea: FeatureArea::Analysis,
            analyticsUserKey: 'user-a',
            visitorKey: 'visitor-a',
            sessionKey: 'session-a',
            context: ['user_role' => 'ROLE_PARTICIPANT'],
            occurredAt: $day,
        ));

        $first = $this->aggregationService->aggregateForDate($day);
        $second = $this->aggregationService->aggregateForDate($day);

        self::assertSame($first->rawRequestCount, $second->rawRequestCount);
        self::assertSame($first->rawEventCount, $second->rawEventCount);
        self::assertSame($first->aggregateRowsWritten, $second->aggregateRowsWritten);
        self::assertSame(2, $first->rawRequestCount);
        self::assertSame(1, $first->rawEventCount);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_aggregation_run WHERE date = :date',
            ['date' => $day->format('Y-m-d')],
        ));
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request_daily WHERE date = :date',
            ['date' => $day->format('Y-m-d')],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT event_count FROM analytics_event_daily WHERE date = :date AND event_name = :event',
            ['date' => $day->format('Y-m-d'), 'event' => UsageEventName::ANALYSIS_EXPLORER_RUN],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT usage_count FROM analytics_filter_param_daily WHERE date = :date AND param_name = :param',
            ['date' => $day->format('Y-m-d'), 'param' => 'period'],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT with_filters FROM analytics_filter_area_daily WHERE date = :date AND feature_area = :area',
            ['date' => $day->format('Y-m-d'), 'area' => FeatureArea::Dashboard->value],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT transition_count FROM analytics_transition_daily WHERE date = :date AND from_route = :fromRoute AND to_route = :toRoute',
            [
                'date' => $day->format('Y-m-d'),
                'fromRoute' => 'app_stats_dashboard',
                'toRoute' => 'app_stats_analysis_explorer',
            ],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT session_count FROM analytics_session_boundary_daily WHERE date = :date AND route_name = :route AND kind = :kind',
            ['date' => $day->format('Y-m-d'), 'route' => 'app_stats_dashboard', 'kind' => 'entry'],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT dau FROM analytics_uniques_daily WHERE date = :date',
            ['date' => $day->format('Y-m-d')],
        ));

        $emptyDay = AnalyticsCalendar::daysAgo(5);
        $empty = $this->aggregationService->aggregateForDate($emptyDay);
        self::assertSame(0, $empty->rawRequestCount);
        self::assertSame(0, $empty->rawEventCount);
        self::assertSame(1, $empty->aggregateRowsWritten);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_aggregation_run WHERE date = :date',
            ['date' => $emptyDay->format('Y-m-d')],
        ));
    }

    public function testHybridCountsMatchRawAndDoNotDoubleCountAggregatedDays(): void
    {
        $twoDaysAgo = AnalyticsCalendar::daysAgo(2)->setTime(10, 0);
        $yesterday = AnalyticsCalendar::yesterday()->setTime(11, 0);
        $today = AnalyticsCalendar::startOfToday()->setTime(12, 0);

        $this->saveRequest($twoDaysAgo, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-h', 'session-h', []);
        $this->saveRequest($twoDaysAgo->modify('+30 minutes'), 'app_stats_analysis_explorer', FeatureArea::Analysis, true, 'ROLE_PARTICIPANT', 'user-h', 'session-h', ['period']);
        $this->saveRequest($twoDaysAgo->modify('+40 minutes'), 'app_explore_allocation', FeatureArea::Explore, false, null, null, 'session-h2', []);
        $this->saveRequest($yesterday, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_ADMIN', 'user-y', 'session-y', []);
        $this->saveRequest($yesterday->modify('+15 minutes'), 'app_home', FeatureArea::Home, false, null, null, 'session-y2', []);
        $this->saveRequest($today, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-t', 'session-t', ['period']);
        $this->eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::ANALYSIS_EXPLORER_RUN,
            featureArea: FeatureArea::Analysis,
            analyticsUserKey: 'user-h',
            visitorKey: 'visitor-h',
            sessionKey: 'session-h',
            context: ['user_role' => 'ROLE_PARTICIPANT'],
            occurredAt: $twoDaysAgo,
        ));
        $this->eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::ANALYSIS_EXPLORER_RUN,
            featureArea: FeatureArea::Analysis,
            analyticsUserKey: 'user-t',
            visitorKey: 'visitor-t',
            sessionKey: 'session-t',
            context: ['user_role' => 'ROLE_PARTICIPANT'],
            occurredAt: $today,
        ));

        $from = AnalyticsCalendar::daysAgo(2);
        $rawTotal = $this->requestRepository->countSince($from);

        $this->aggregationService->aggregateForDate($twoDaysAgo);

        $hybridTotal = $this->reportingQuery->countSince($from);
        self::assertSame($rawTotal, $hybridTotal);
        self::assertSame(6, $hybridTotal);

        $featureAreas = $this->reportingQuery->featureAreaDistributionSince($from);
        $byArea = [];
        foreach ($featureAreas as $row) {
            $byArea[$row['featureArea']] = $row['requestCount'];
        }
        self::assertSame(3, $byArea['dashboard'] ?? 0);
        self::assertSame(1, $byArea['analysis'] ?? 0);
        self::assertSame(1, $byArea['explore'] ?? 0);
        self::assertSame(1, $byArea['home'] ?? 0);

        $auth = $this->reportingQuery->authenticationSplitSince($from);
        self::assertSame(4, $auth['authenticated']);
        self::assertSame(2, $auth['anonymous']);

        $routes = $this->reportingQuery->topRoutesSince($from);
        $byRoute = [];
        foreach ($routes as $row) {
            $byRoute[$row['routeName']] = $row['requestCount'];
        }
        self::assertSame(3, $byRoute['app_stats_dashboard'] ?? 0);
        self::assertSame(1, $byRoute['app_stats_analysis_explorer'] ?? 0);

        $roleArea = $this->reportingQuery->roleAreaMatrixSince($from);
        self::assertNotEmpty($roleArea);
        self::assertGreaterThanOrEqual(1, array_sum(array_column($roleArea, 'requestCount')));

        $events = $this->reportingQuery->topEventsSince($from);
        self::assertSame(UsageEventName::ANALYSIS_EXPLORER_RUN, $events[0]['eventName'] ?? null);
        self::assertSame(2, $events[0]['eventCount'] ?? 0);
        self::assertSame(2, $events[0]['uniqueUsers'] ?? 0);

        $eventsByRole = $this->reportingQuery->eventsByRoleSince($from);
        self::assertSame('ROLE_PARTICIPANT', $eventsByRole[0]['userRole'] ?? null);
        self::assertSame(2, $eventsByRole[0]['eventCount'] ?? 0);

        $filters = $this->reportingQuery->topFilterParamsSince($from);
        self::assertSame('period', $filters[0]['paramName'] ?? null);
        self::assertSame(2, $filters[0]['usageCount'] ?? 0);

        $filterAreas = $this->reportingQuery->filterUsageByAreaSince($from);
        $dashboardFilters = array_find($filterAreas, fn ($row): bool => 'dashboard' === $row['featureArea']);
        self::assertNotNull($dashboardFilters);
        self::assertSame(1, $dashboardFilters['withFilters']);
        self::assertSame(2, $dashboardFilters['withoutFilters']);
    }

    public function testCleanupDeletesOnlyAggregatedDaysOlderThanRetention(): void
    {
        $expired = AnalyticsCalendar::daysAgo(40)->setTime(9, 0);
        $recent = AnalyticsCalendar::daysAgo(5)->setTime(9, 0);
        $unaggregatedExpired = AnalyticsCalendar::daysAgo(35)->setTime(9, 0);

        $this->saveRequest($expired, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-old', 'session-old', []);
        $this->saveRequest($recent, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-new', 'session-new', []);
        $this->saveRequest($unaggregatedExpired, 'app_home', FeatureArea::Home, false, null, null, 'session-gap', []);
        $this->eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::USER_REGISTERED,
            analyticsUserKey: 'user-old',
            occurredAt: $expired,
        ));

        $this->aggregationService->aggregateForDate($expired);
        $this->aggregationService->aggregateForDate($recent);

        $dryRun = $this->retentionService->purgeExpiredRaw(true);
        self::assertContains($expired->format('Y-m-d'), $dryRun->datesCleaned);
        self::assertNotContains($recent->format('Y-m-d'), $dryRun->datesCleaned);
        self::assertNotContains($unaggregatedExpired->format('Y-m-d'), $dryRun->datesCleaned);
        self::assertSame(1, $dryRun->requestsDeleted);
        self::assertSame(1, $dryRun->eventsDeleted);
        self::assertSame(1, $this->countRequestsOn($expired));
        self::assertSame(1, $this->countEventsOn($expired));

        $purged = $this->retentionService->purgeExpiredRaw();
        self::assertSame(1, $purged->requestsDeleted);
        self::assertSame(1, $purged->eventsDeleted);
        self::assertSame(0, $this->countRequestsOn($expired));
        self::assertSame(0, $this->countEventsOn($expired));
        self::assertSame(1, $this->countRequestsOn($recent));
        self::assertSame(1, $this->countRequestsOn($unaggregatedExpired));
    }

    public function testScheduledRunAggregatesYesterdayCatchUpAndCleansExpiredRaw(): void
    {
        $expired = AnalyticsCalendar::daysAgo(40)->setTime(8, 0);
        $yesterday = AnalyticsCalendar::yesterday()->setTime(8, 0);

        $this->saveRequest($expired, 'app_stats_dashboard', FeatureArea::Dashboard, true, 'ROLE_PARTICIPANT', 'user-s', 'session-s', []);
        $this->saveRequest($yesterday, 'app_stats_analysis_explorer', FeatureArea::Analysis, true, 'ROLE_PARTICIPANT', 'user-s', 'session-s', ['period']);

        $scheduled = self::getContainer()->get(AnalyticsScheduledAggregationService::class);
        $result = $scheduled->run();

        self::assertContains($expired->format('Y-m-d'), $result->dates);
        self::assertContains($yesterday->format('Y-m-d'), $result->dates);
        self::assertSame(0, $this->countRequestsOn($expired));
        self::assertSame(1, $this->countRequestsOn($yesterday));
        self::assertGreaterThanOrEqual(1, $result->rawRowsDeleted);
    }

    /**
     * @param list<string> $queryParamNames
     */
    private function saveRequest(
        \DateTimeImmutable $occurredAt,
        ?string $routeName,
        FeatureArea $featureArea,
        bool $isAuthenticated,
        ?string $userRole,
        ?string $analyticsUserKey,
        ?string $sessionKey,
        array $queryParamNames,
    ): void {
        $this->requestRepository->save(new AnalyticsRequest(
            occurredAt: $occurredAt,
            routeName: $routeName,
            featureArea: $featureArea,
            httpStatus: 200,
            durationMs: 120,
            dbQueryCount: 3,
            dbTimeMs: 40,
            isAuthenticated: $isAuthenticated,
            userRole: $userRole,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: 'visitor-agg',
            sessionKey: $sessionKey,
            browserFamily: BrowserFamily::Firefox,
            deviceType: DeviceType::Desktop,
            queryParamNames: $queryParamNames,
        ));
    }

    private function countRequestsOn(\DateTimeImmutable $day): int
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE occurred_at >= :from AND occurred_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );
    }

    private function countEventsOn(\DateTimeImmutable $day): int
    {
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_product_event WHERE occurred_at >= :from AND occurred_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );
    }
}
