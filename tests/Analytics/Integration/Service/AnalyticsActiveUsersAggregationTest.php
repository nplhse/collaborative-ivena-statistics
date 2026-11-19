<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Service;

use App\Analytics\Application\Service\AnalyticsDailyAggregationService;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Rolling WAU/MAU written by the daily aggregation (#1322). Today is 19.11.2026 (Berlin), the raw retention is
 * 30 days, so raw rows are guaranteed from 20.10.2026 on (the retention cutoff).
 */
final class AnalyticsActiveUsersAggregationTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::mockTime('2026-11-19 10:00:00');

        $this->visit('user-a', '2026-11-18 09:00:00'); // the day itself
        $this->visit('user-a', '2026-11-18 10:00:00'); // counted once
        $this->visit('user-b', '2026-11-12 12:00:00'); // first day of the 7-day window of 18.11.
        $this->visit('user-c', '2026-11-11 23:30:00'); // before that window, inside the 30-day window
        $this->visit('user-d', '2026-10-20 00:30:00'); // first day of the 30-day window of 18.11.
        $this->visit('user-e', '2026-10-19 23:30:00'); // before the 30-day window
    }

    public function testWauAndMauCountDistinctUsersOfTheWindowsEndingWithTheDay(): void
    {
        $this->aggregate('2026-11-18');

        self::assertSame(['wau' => 2, 'mau' => 4], $this->stored('2026-11-18'), 'WAU: a, b; MAU: a, b, c, d');
    }

    public function testAWindowStartingBeforeTheRetentionCutoffIsNotCounted(): void
    {
        $this->aggregate('2026-11-17');

        // 7 days 11.11.–17.11.: b, c. The 30 days 19.10.–17.11. start before the cutoff 20.10.: not counted.
        self::assertSame(['wau' => 2, 'mau' => null], $this->stored('2026-11-17'));
    }

    public function testAnIncompleteWindowKeepsTheValueCountedBefore(): void
    {
        $this->connection->insert('analytics_active_users_daily', ['date' => '2026-11-17', 'wau' => 9, 'mau' => 9]);

        $this->aggregate('2026-11-17');

        self::assertSame(['wau' => 2, 'mau' => 9], $this->stored('2026-11-17'), 'WAU is recounted, MAU is kept');
    }

    public function testNoRowWhenNeitherWindowCanBeCounted(): void
    {
        // 7 days 19.10.–25.10. and 30 days 26.09.–25.10. both start before the cutoff 20.10.
        $this->aggregate('2026-10-25');

        self::assertNull($this->stored('2026-10-25'));
    }

    private function aggregate(string $date): void
    {
        self::getContainer()->get(AnalyticsDailyAggregationService::class)
            ->aggregateForDate(new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin')));
    }

    /**
     * @return array{wau: ?int, mau: ?int}|null
     */
    private function stored(string $date): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT wau, mau FROM analytics_active_users_daily WHERE date = :date', ['date' => $date]);
        if (false === $row) {
            return null;
        }

        return [
            'wau' => null === $row['wau'] ? null : (int) $row['wau'],
            'mau' => null === $row['mau'] ? null : (int) $row['mau'],
        ];
    }

    private function visit(string $userKey, string $berlinTime): void
    {
        self::getContainer()->get(AnalyticsRequestRepository::class)->save(new AnalyticsRequest(
            occurredAt: new \DateTimeImmutable($berlinTime, new \DateTimeZone('Europe/Berlin')),
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            httpStatus: 200,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: $userKey,
            visitorKey: 'visitor-'.$userKey,
            sessionKey: 'session-'.$userKey,
            browserFamily: BrowserFamily::Firefox,
            deviceType: DeviceType::Desktop,
        ));
    }
}
