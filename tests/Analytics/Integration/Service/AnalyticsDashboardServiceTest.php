<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\Service\AnalyticsDashboardService;
use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class AnalyticsDashboardServiceTest extends DatabaseKernelTestCase
{
    public function testAllSectionsReturnAggregatesFromSeededData(): void
    {
        self::bootKernel();

        $requestRepository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $eventRepository = self::getContainer()->get(AnalyticsProductEventRepository::class);
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
            queryParamNames: [],
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
            queryParamNames: ['period'],
        ));

        $eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::USER_REGISTERED,
            featureArea: null,
            analyticsUserKey: $userKey,
            visitorKey: 'dashboard-visitor-1',
            sessionKey: $session,
            context: ['user_role' => 'ROLE_USER'],
            occurredAt: $today->modify('-10 days'),
        ));
        $eventRepository->save(new AnalyticsProductEvent(
            eventName: UsageEventName::ANALYSIS_EXPLORER_RUN,
            featureArea: FeatureArea::Analysis,
            analyticsUserKey: $userKey,
            visitorKey: 'dashboard-visitor-1',
            sessionKey: $session,
            context: ['user_role' => 'ROLE_PARTICIPANT'],
            occurredAt: $today->modify('-2 days'),
        ));

        $overview = $service->getOverview();
        self::assertGreaterThanOrEqual(2, $overview->requestsToday);
        self::assertNotEmpty($overview->featureAreas);
        self::assertNotEmpty($overview->topRoutes);
        self::assertGreaterThanOrEqual(1, $overview->authenticationSplit['authenticated']);
        self::assertGreaterThanOrEqual(1, $overview->retention['dau']);

        $adoption = $service->getAdoption();
        self::assertNotEmpty($adoption->topEvents);
        self::assertNotEmpty($adoption->engagementDepth);
        self::assertSame(0, $adoption->engagementDepth[0]['level']);
        self::assertGreaterThanOrEqual(1, array_sum(array_column($adoption->engagementDepth, 'userCount')));

        $journeys = $service->getJourneys();
        self::assertNotEmpty($journeys->onboardingFunnel);
        self::assertNotEmpty($journeys->entryRoutes);
        self::assertNotEmpty($journeys->exitRoutes);
        self::assertCount(3, $journeys->timeToFirst);

        $filters = $service->getFilters();
        self::assertNotEmpty($filters->topFilterParams);
        self::assertContains('period', array_column($filters->topFilterParams, 'paramName'));

        $performance = $service->getPerformance();
        self::assertNotEmpty($performance->performanceByArea);
        self::assertIsArray($performance->performanceInsights);
    }
}
