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
        $today = $this->day('2026-11-20');

        // Day with daily counts (2 signed in, 3 anonymous) that still has a raw row: the raw row is ignored.
        $connection->executeStatement(
            "INSERT INTO analytics_access_daily (date, is_authenticated, access_count) VALUES ('2026-11-15', true, 2), ('2026-11-15', false, 3)",
        );
        $this->saveRequest($this->day('2026-11-15')->setTime(10, 0), true, true);

        // Day not aggregated yet: counted from raw rows, 1 signed in and 1 anonymous.
        $this->saveRequest($this->day('2026-11-18')->setTime(9, 0), true, true);
        $this->saveRequest($this->day('2026-11-18')->setTime(9, 1), false, true);
        $this->saveRequest($this->day('2026-11-18')->setTime(9, 2), false, false);

        // Day with an unclassified request: left out.
        $this->saveRequest($this->day('2026-11-19')->setTime(9, 0), true, true);
        $this->saveRequest($this->day('2026-11-19')->setTime(9, 1), true, null);

        $months = self::getContainer()->get(AnalyticsAccessTrendQuery::class)->monthlyAccesses($today);

        // November: 2 + 1 signed in, 3 + 1 anonymous; the first day with data is the 15th.
        self::assertSame([
            ['month' => '2026-11', 'firstDay' => '2026-11-15', 'authenticated' => 3, 'anonymous' => 4],
        ], $months);
    }

    public function testSumsDaysIntoCalendarMonthsUpToToday(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        // Last day of September and first day of October fall into different months; 2026-11-03
        // lies after "today" and is not counted.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO analytics_access_daily (date, is_authenticated, access_count) VALUES
                ('2026-09-30', true, 1), ('2026-09-30', false, 2),
                ('2026-10-01', true, 4),
                ('2026-10-31', false, 5),
                ('2026-11-01', true, 7),
                ('2026-11-03', true, 9)
            SQL);
        // A raw-only day late in the evening stays in October.
        $this->saveRequest($this->day('2026-10-30')->setTime(23, 59), true, true);

        $months = self::getContainer()->get(AnalyticsAccessTrendQuery::class)->monthlyAccesses($this->day('2026-11-02'));

        self::assertSame([
            ['month' => '2026-09', 'firstDay' => '2026-09-30', 'authenticated' => 1, 'anonymous' => 2],
            ['month' => '2026-10', 'firstDay' => '2026-10-01', 'authenticated' => 5, 'anonymous' => 5],
            ['month' => '2026-11', 'firstDay' => '2026-11-01', 'authenticated' => 7, 'anonymous' => 0],
        ], $months);
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, AnalyticsCalendar::timezone());
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
