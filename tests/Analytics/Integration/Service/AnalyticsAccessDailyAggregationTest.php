<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\Service\AnalyticsDailyAggregationService;
use App\Analytics\Application\Service\AnalyticsRawRetentionService;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;

/**
 * Daily counted accesses (#950). Every expected value is counted from the rows saved in the test.
 */
final class AnalyticsAccessDailyAggregationTest extends DatabaseKernelTestCase
{
    private AnalyticsDailyAggregationService $aggregationService;

    private AnalyticsRequestRepository $repository;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->aggregationService = self::getContainer()->get(AnalyticsDailyAggregationService::class);
        $this->repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testCountsOnlyCountedAccessesSplitByAuthentication(): void
    {
        $day = AnalyticsCalendar::daysAgo(3);
        // Counted: 2 signed in, 1 anonymous. Not counted: 2 requests.
        $this->saveRequest($day->setTime(9, 0), true, true);
        $this->saveRequest($day->setTime(9, 5), true, true);
        $this->saveRequest($day->setTime(10, 0), false, true);
        $this->saveRequest($day->setTime(10, 5), true, false);
        $this->saveRequest($day->setTime(11, 0), false, false);

        $result = $this->aggregationService->aggregateForDate($day);

        self::assertSame(0, $result->unclassifiedRequests);
        self::assertSame(['signed_in' => 2, 'anonymous' => 1], $this->accessesOn($day));
    }

    public function testDayBoundariesFollowTheBerlinCalendarDay(): void
    {
        $first = AnalyticsCalendar::daysAgo(4);
        $second = AnalyticsCalendar::daysAgo(3);
        $this->saveRequest($first->setTime(23, 59, 59), false, true);
        $this->saveRequest($second->setTime(0, 0, 0), false, true);
        $this->saveRequest($second->setTime(0, 0, 1), false, true);

        $this->aggregationService->aggregateForDate($first);
        $this->aggregationService->aggregateForDate($second);

        self::assertSame(['signed_in' => 0, 'anonymous' => 1], $this->accessesOn($first));
        self::assertSame(['signed_in' => 0, 'anonymous' => 2], $this->accessesOn($second));
    }

    public function testDayWithUnclassifiedRequestsGetsNoAccessCounts(): void
    {
        $day = AnalyticsCalendar::daysAgo(3);
        $this->saveRequest($day->setTime(9, 0), false, true);
        $this->saveRequest($day->setTime(9, 5), false, null);

        $result = $this->aggregationService->aggregateForDate($day);

        self::assertSame(1, $result->unclassifiedRequests);
        self::assertNull($this->accessesOn($day));
        // The other daily aggregates are written as before.
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT SUM(request_count) FROM analytics_request_daily WHERE date = :date',
            ['date' => $day->format('Y-m-d')],
        ));
    }

    public function testEmptyDaysCountAsZeroOnlyAfterCountingStarted(): void
    {
        $beforeStart = AnalyticsCalendar::daysAgo(6);
        $start = AnalyticsCalendar::daysAgo(5);
        $afterStart = AnalyticsCalendar::daysAgo(4);
        $this->saveRequest($start->setTime(12, 0), true, true);

        $this->aggregationService->aggregateForDate($beforeStart);
        $this->aggregationService->aggregateForDate($start);
        $this->aggregationService->aggregateForDate($afterStart);

        self::assertNull($this->accessesOn($beforeStart));
        self::assertSame(['signed_in' => 1, 'anonymous' => 0], $this->accessesOn($start));
        self::assertSame(['signed_in' => 0, 'anonymous' => 0], $this->accessesOn($afterStart));
    }

    public function testReAggregationDoesNotDoubleCountAndPurgeKeepsCounts(): void
    {
        $day = AnalyticsCalendar::daysAgo(40);
        $this->saveRequest($day->setTime(8, 0), true, true);
        $this->saveRequest($day->setTime(8, 30), false, true);
        $this->saveRequest($day->setTime(9, 0), false, false);

        $this->aggregationService->aggregateForDate($day);
        $this->aggregationService->aggregateForDate($day);
        self::assertSame(['signed_in' => 1, 'anonymous' => 1], $this->accessesOn($day));

        self::getContainer()->get(AnalyticsRawRetentionService::class)->purgeExpiredRaw();
        $this->aggregationService->aggregateForDate($day);

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE occurred_at >= :from AND occurred_at < :to',
            ['from' => $day->format('Y-m-d 00:00:00'), 'to' => $day->modify('+1 day')->format('Y-m-d 00:00:00')],
        ));
        self::assertSame(['signed_in' => 1, 'anonymous' => 1], $this->accessesOn($day));
    }

    /**
     * @return array{signed_in: int, anonymous: int}|null null when the day has no access counts
     */
    private function accessesOn(\DateTimeImmutable $day): ?array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT is_authenticated::int, access_count FROM analytics_access_daily WHERE date = :date',
            ['date' => $day->format('Y-m-d')],
        );

        if ([] === $rows) {
            return null;
        }

        return [
            'signed_in' => (int) ($rows[1] ?? -1),
            'anonymous' => (int) ($rows[0] ?? -1),
        ];
    }

    private function saveRequest(\DateTimeImmutable $occurredAt, bool $isAuthenticated, ?bool $countedAccess): void
    {
        $this->repository->save(new AnalyticsRequest(
            occurredAt: $occurredAt,
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            httpStatus: 200,
            durationMs: 50,
            dbQueryCount: 1,
            dbTimeMs: 5,
            isAuthenticated: $isAuthenticated,
            userRole: $isAuthenticated ? 'ROLE_PARTICIPANT' : null,
            analyticsUserKey: null,
            visitorKey: null,
            sessionKey: null,
            browserFamily: BrowserFamily::Chrome,
            deviceType: DeviceType::Desktop,
            countedAccess: $countedAccess,
        ));
    }
}
