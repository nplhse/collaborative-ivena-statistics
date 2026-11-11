<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\Service\AnalyticsDashboardService;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class AnalyticsDashboardServiceTest extends DatabaseKernelTestCase
{
    public function testAllSectionsReturnAggregatesFromSeededData(): void
    {
        self::bootKernel();

        $requestRepository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $service = self::getContainer()->get(AnalyticsDashboardService::class);

        // Noon in Europe/Berlin so requestsToday stays valid around midnight.
        $today = $requestRepository->startOfToday()->modify('+12 hours');
        $userKey = 'dashboard-user-1';
        $session = 'dashboard-session-1';

        $requestRepository->save(new AnalyticsRequest(
            occurredAt: $today->modify('-1 hour'),
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            httpStatus: 200,
            durationMs: 120,
            dbQueryCount: 3,
            dbTimeMs: 40,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            visitorKey: 'dashboard-visitor-1',
            sessionKey: $session,
            browserFamily: BrowserFamily::Firefox,
            deviceType: DeviceType::Desktop,
        ));
        $requestRepository->save(new AnalyticsRequest(
            occurredAt: $today->modify('-50 minutes'),
            routeName: 'app_stats_analysis_explorer',
            featureArea: FeatureArea::Analysis,
            httpStatus: 200,
            durationMs: 500,
            dbQueryCount: 15,
            dbTimeMs: 200,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            visitorKey: 'dashboard-visitor-1',
            sessionKey: $session,
            browserFamily: BrowserFamily::Firefox,
            deviceType: DeviceType::Desktop,
        ));

        $overview = $service->getOverview();
        self::assertGreaterThanOrEqual(2, $overview->requestsToday);
        self::assertNotEmpty($overview->featureAreas);
        self::assertNotEmpty($overview->topRoutes);
        self::assertGreaterThanOrEqual(1, $overview->authenticationSplit['authenticated']);
        self::assertGreaterThanOrEqual(1, $overview->retention['dau']);

        $performance = $service->getPerformance();
        self::assertNotEmpty($performance->performanceByArea);
        self::assertIsArray($performance->performanceInsights);
    }
}
