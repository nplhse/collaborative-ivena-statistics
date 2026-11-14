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

    public function testAggregatesCoverCountsRoutesAuthAndRetention(): void
    {
        // Noon in Europe/Berlin so "today" counts stay valid around midnight.
        $today = $this->repository->startOfToday()->modify('+12 hours');
        $session = 'session-agg-1';
        $userKey = 'user-key-agg-1';

        $this->saveRequest(
            occurredAt: $today->modify('-10 minutes'),
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            sessionKey: $session,
        );
        $this->saveRequest(
            occurredAt: $today->modify('-8 minutes'),
            routeName: 'app_stats_analysis_explorer',
            featureArea: FeatureArea::Analysis,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            sessionKey: $session,
        );
        $this->saveRequest(
            occurredAt: $today->modify('-5 minutes'),
            routeName: 'app_explore_allocation',
            featureArea: FeatureArea::Explore,
            isAuthenticated: false,
            userRole: null,
            analyticsUserKey: null,
            sessionKey: $session,
        );

        for ($i = 0; $i < 5; ++$i) {
            $this->saveRequest(
                occurredAt: $today->modify(sprintf('-%d minutes', 20 + $i)),
                routeName: 'app_stats_analysis_explorer',
                featureArea: FeatureArea::Analysis,
                isAuthenticated: true,
                userRole: 'ROLE_ADMIN',
                analyticsUserKey: 'user-key-agg-2',
                sessionKey: 'session-agg-2',
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
    }

    private function saveRequest(
        \DateTimeImmutable $occurredAt,
        ?string $routeName,
        FeatureArea $featureArea,
        bool $isAuthenticated,
        ?string $userRole,
        ?string $analyticsUserKey,
        ?string $sessionKey,
    ): void {
        $this->repository->save(new AnalyticsRequest(
            occurredAt: $occurredAt,
            routeName: $routeName,
            featureArea: $featureArea,
            httpStatus: 200,
            isAuthenticated: $isAuthenticated,
            userRole: $userRole,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: 'visitor-agg-1',
            sessionKey: $sessionKey,
            browserFamily: BrowserFamily::Chrome,
            deviceType: DeviceType::Desktop,
        ));
    }
}
