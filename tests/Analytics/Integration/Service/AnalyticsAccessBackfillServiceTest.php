<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\RequestTracking\CountedAccessClassifier;
use App\Analytics\Application\Service\AnalyticsAccessBackfillService;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\RouterInterface;

final class AnalyticsAccessBackfillServiceTest extends DatabaseKernelTestCase
{
    private AnalyticsAccessBackfillService $service;

    private AnalyticsRequestRepository $repository;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(AnalyticsAccessBackfillService::class);
        $this->repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testClassifiesOnlyUnclassifiedRowsAndIsIdempotent(): void
    {
        // Unclassified rows (recorded before the flag existed): 2 page views, 5 not counted.
        $this->saveRequest('app_stats_dashboard', 200, DeviceType::Desktop, null);
        $this->saveRequest('app_stats_dashboard', 200, DeviceType::Mobile, null);
        $this->saveRequest('app_stats_dashboard', 302, DeviceType::Desktop, null);
        $this->saveRequest('app_default', 200, DeviceType::Bot, null);
        $this->saveRequest('app_robots_txt', 200, DeviceType::Desktop, null);
        $this->saveRequest('app_stats_overview_top_reports', 200, DeviceType::Desktop, null);
        $this->saveRequest(null, 404, DeviceType::Desktop, null);
        // Already classified at record time: must stay as it is.
        $this->saveRequest('app_stats_dashboard', 200, DeviceType::Desktop, false);

        $preview = $this->service->classifyStoredRequests(dryRun: true);
        self::assertSame(7, $preview->rowsClassified);
        self::assertSame(2, $preview->rowsCounted);
        self::assertSame(7, $this->countWhere('counted_access IS NULL'));

        $result = $this->service->classifyStoredRequests();
        self::assertSame(7, $result->rowsClassified);
        self::assertSame(2, $result->rowsCounted);
        self::assertSame(0, $this->countWhere('counted_access IS NULL'));
        self::assertSame(2, $this->countWhere('counted_access = true'));
        self::assertSame(6, $this->countWhere('counted_access = false'));

        $again = $this->service->classifyStoredRequests();
        self::assertSame(0, $again->rowsClassified);
        self::assertSame(2, $this->countWhere('counted_access = true'));
    }

    public function testEveryExcludedRouteExists(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach (CountedAccessClassifier::NON_PAGE_ROUTES as $routeName) {
            self::assertNotNull($routes->get($routeName), sprintf('Route "%s" does not exist; update CountedAccessClassifier.', $routeName));
        }
    }

    private function saveRequest(?string $routeName, int $status, DeviceType $deviceType, ?bool $countedAccess): void
    {
        $this->repository->save(new AnalyticsRequest(
            occurredAt: AnalyticsCalendar::daysAgo(3)->setTime(10, 0),
            routeName: $routeName,
            featureArea: FeatureArea::Other,
            httpStatus: $status,
            durationMs: 50,
            dbQueryCount: 1,
            dbTimeMs: 5,
            isAuthenticated: false,
            userRole: null,
            analyticsUserKey: null,
            visitorKey: null,
            sessionKey: null,
            browserFamily: BrowserFamily::Chrome,
            deviceType: $deviceType,
            countedAccess: $countedAccess,
        ));
    }

    private function countWhere(string $condition): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM analytics_request WHERE '.$condition);
    }
}
