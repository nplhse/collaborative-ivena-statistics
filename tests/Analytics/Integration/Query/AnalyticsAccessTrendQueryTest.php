<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Query;

use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Query\AnalyticsAccessTrendQuery;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;

final class AnalyticsAccessTrendQueryTest extends DatabaseKernelTestCase
{
    public function testCombinesAggregatedAndRawDaysWithoutDoubleCounting(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $aggregated = AnalyticsCalendar::daysAgo(5);
        $rawOnly = AnalyticsCalendar::daysAgo(2);
        $unclassified = AnalyticsCalendar::daysAgo(1);

        // Day with daily counts (2 signed in, 3 anonymous) that still has a raw row: the raw row is ignored.
        $connection->executeStatement(
            'INSERT INTO analytics_access_daily (date, is_authenticated, access_count) VALUES (:date, true, 2), (:date, false, 3)',
            ['date' => $aggregated->format('Y-m-d')],
        );
        $this->saveRequest($aggregated->setTime(10, 0), true, true);

        // Day not aggregated yet: counted from raw rows, 1 signed in and 1 anonymous.
        $this->saveRequest($rawOnly->setTime(9, 0), true, true);
        $this->saveRequest($rawOnly->setTime(9, 1), false, true);
        $this->saveRequest($rawOnly->setTime(9, 2), false, false);

        // Day with an unclassified request: left out.
        $this->saveRequest($unclassified->setTime(9, 0), true, true);
        $this->saveRequest($unclassified->setTime(9, 1), true, null);

        $days = self::getContainer()->get(AnalyticsAccessTrendQuery::class)->dailyAccesses();

        self::assertSame([
            ['date' => $aggregated->format('Y-m-d'), 'authenticated' => 2, 'anonymous' => 3],
            ['date' => $rawOnly->format('Y-m-d'), 'authenticated' => 1, 'anonymous' => 1],
        ], $days);
    }

    private function saveRequest(\DateTimeImmutable $occurredAt, bool $isAuthenticated, ?bool $countedAccess): void
    {
        self::getContainer()->get(AnalyticsRequestRepository::class)->save(new AnalyticsRequest(
            occurredAt: $occurredAt,
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            httpStatus: 200,
            isAuthenticated: $isAuthenticated,
            userRole: null,
            analyticsUserKey: null,
            visitorKey: null,
            sessionKey: null,
            browserFamily: BrowserFamily::Chrome,
            deviceType: DeviceType::Desktop,
            countedAccess: $countedAccess,
        ));
    }
}
