<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Repository;

use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class AnalyticsRequestRepositoryTest extends DatabaseKernelTestCase
{
    private AnalyticsRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(AnalyticsRequestRepository::class);
    }

    public function testAggregatesCoverCountsRoutesAuthRetentionAndFilters(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $session = 'session-agg-1';
        $userKey = 'user-key-agg-1';

        $this->saveRequest(
            occurredAt: $now->modify('-10 minutes'),
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            sessionKey: $session,
            queryParamNames: [],
            durationMs: 100,
            dbQueryCount: 2,
        );
        $this->saveRequest(
            occurredAt: $now->modify('-8 minutes'),
            routeName: 'app_stats_analysis_explorer',
            featureArea: FeatureArea::Analysis,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            sessionKey: $session,
            queryParamNames: ['period', 'scope'],
            durationMs: 400,
            dbQueryCount: 12,
        );
        $this->saveRequest(
            occurredAt: $now->modify('-5 minutes'),
            routeName: 'app_explore_allocation',
            featureArea: FeatureArea::Explore,
            isAuthenticated: false,
            userRole: null,
            analyticsUserKey: null,
            sessionKey: $session,
            queryParamNames: [],
            durationMs: 150,
            dbQueryCount: 3,
        );

        for ($i = 0; $i < 5; ++$i) {
            $this->saveRequest(
                occurredAt: $now->modify(sprintf('-%d minutes', 20 + $i)),
                routeName: 'app_stats_analysis_explorer',
                featureArea: FeatureArea::Analysis,
                isAuthenticated: true,
                userRole: 'ROLE_ADMIN',
                analyticsUserKey: 'user-key-agg-2',
                sessionKey: 'session-agg-2',
                queryParamNames: ['period'],
                durationMs: 800 + ($i * 10),
                dbQueryCount: 20,
            );
        }

        self::assertGreaterThanOrEqual(8, $this->repository->countToday());
        self::assertGreaterThanOrEqual(8, $this->repository->countLast7Days());
        self::assertGreaterThanOrEqual(8, $this->repository->countLast30Days());

        $from = $this->repository->daysAgo(6);

        $topRoutes = $this->repository->topRoutesSince($from);
        self::assertNotEmpty($topRoutes);
        self::assertSame('app_stats_analysis_explorer', $topRoutes[0]['routeName']);
        self::assertGreaterThanOrEqual(6, $topRoutes[0]['requestCount']);

        $areas = $this->repository->featureAreaDistributionSince($from);
        self::assertNotEmpty($areas);
        $areaNames = array_column($areas, 'featureArea');
        self::assertContains('analysis', $areaNames);
        self::assertContains('dashboard', $areaNames);

        $authSplit = $this->repository->authenticationSplitSince($from);
        self::assertGreaterThanOrEqual(7, $authSplit['authenticated']);
        self::assertGreaterThanOrEqual(1, $authSplit['anonymous']);

        $retention = $this->repository->retentionSnapshot();
        self::assertGreaterThanOrEqual(2, $retention['dau']);
        self::assertGreaterThanOrEqual(2, $retention['wau']);
        self::assertGreaterThanOrEqual(2, $retention['mau']);
        self::assertGreaterThanOrEqual(2, $retention['sessionsLast7Days']);

        $matrix = $this->repository->roleAreaMatrixSince($from);
        self::assertNotEmpty($matrix);
        self::assertContains('ROLE_PARTICIPANT', array_column($matrix, 'userRole'));

        $levels = $this->repository->maxRequestLevelsByUserSince($from);
        self::assertArrayHasKey($userKey, $levels);
        self::assertGreaterThanOrEqual(4, $levels[$userKey]);

        $entries = $this->repository->topEntryRoutesSince($from);
        self::assertNotEmpty($entries);
        self::assertContains('app_stats_dashboard', array_column($entries, 'routeName'));

        $exits = $this->repository->topExitRoutesSince($from);
        self::assertNotEmpty($exits);
        self::assertContains('app_explore_allocation', array_column($exits, 'routeName'));

        $transitions = $this->repository->topTransitionsSince($from);
        self::assertNotEmpty($transitions);
        $fromRoutes = array_column($transitions, 'fromRoute');
        self::assertTrue(
            \in_array('app_stats_dashboard', $fromRoutes, true)
            || \in_array('app_stats_analysis_explorer', $fromRoutes, true),
        );

        $filterParams = $this->repository->topFilterParamsSince($from);
        self::assertNotEmpty($filterParams);
        self::assertContains('period', array_column($filterParams, 'paramName'));

        $filterByArea = $this->repository->filterUsageByAreaSince($from);
        self::assertNotEmpty($filterByArea);
        $analysisFilter = array_find($filterByArea, fn ($row): bool => 'analysis' === $row['featureArea']);
        self::assertNotNull($analysisFilter);
        self::assertGreaterThan(0, $analysisFilter['withFilters']);

        $perf = $this->repository->performanceByAreaSince($from);
        self::assertNotEmpty($perf);
        self::assertArrayHasKey('avgDurationMs', $perf[0]);
        self::assertArrayHasKey('p95DurationMs', $perf[0]);

        $slowest = $this->repository->slowestRoutesSince($from, minCount: 5);
        self::assertNotEmpty($slowest);
        self::assertSame('app_stats_analysis_explorer', $slowest[0]['routeName']);
    }

    public function testBuildPerformanceInsights(): void
    {
        self::assertSame([], $this->repository->buildPerformanceInsights([
            [
                'featureArea' => 'analysis',
                'requestCount' => 10,
                'avgDurationMs' => 100.0,
                'avgQueries' => 5.0,
            ],
        ]));

        $insights = $this->repository->buildPerformanceInsights([
            [
                'featureArea' => 'analysis',
                'requestCount' => 100,
                'avgDurationMs' => 900.0,
                'avgQueries' => 5.0,
            ],
            [
                'featureArea' => 'dashboard',
                'requestCount' => 80,
                'avgDurationMs' => 50.0,
                'avgQueries' => 2.0,
            ],
            [
                'featureArea' => 'admin',
                'requestCount' => 2,
                'avgDurationMs' => 40.0,
                'avgQueries' => 40.0,
            ],
        ]);

        self::assertNotEmpty($insights);
        self::assertTrue(array_any(
            $insights,
            static fn (string $line): bool => str_contains($line, 'High use + slow') && str_contains($line, 'analysis'),
        ));
        self::assertTrue(array_any(
            $insights,
            static fn (string $line): bool => str_contains($line, 'Low use + heavy DB') && str_contains($line, 'admin'),
        ));
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
        int $durationMs = 100,
        int $dbQueryCount = 1,
    ): void {
        $this->repository->save(new AnalyticsRequest(
            occurredAt: $occurredAt,
            routeName: $routeName,
            featureArea: $featureArea,
            httpStatus: 200,
            durationMs: $durationMs,
            dbQueryCount: $dbQueryCount,
            dbTimeMs: max(1, (int) ($durationMs / 2)),
            isAuthenticated: $isAuthenticated,
            userRole: $userRole,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: 'visitor-agg-1',
            sessionKey: $sessionKey,
            browserFamily: BrowserFamily::Chrome,
            deviceType: DeviceType::Desktop,
            queryParamNames: $queryParamNames,
        ));
    }
}
