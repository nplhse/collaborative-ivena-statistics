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
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Usage trend and previous MAU on the admin dashboard (#1322). Today is 19.11.2026 (Berlin).
 */
final class AnalyticsUsageTrendTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::mockTime('2026-11-19 10:00:00');

        $this->aggregatedDay('2026-11-18', dau: 3, wau: 5, mau: 8);
        $this->aggregatedDay('2026-11-17', dau: null, wau: 4, mau: null); // no requests that day
        $this->aggregatedDay('2026-10-20', dau: 1, wau: null, mau: 6);    // 30 days before today

        $this->visit('user-x', '2026-11-19 08:00:00');
        $this->visit('user-y', '2026-11-19 09:00:00');
        $this->visit('user-z', '2026-11-15 09:00:00');
    }

    public function testTrendShowsAggregatedDaysAndTodayFromRawRows(): void
    {
        $trend = self::getContainer()->get(AnalyticsDashboardService::class)->getUsageTrend();

        self::assertCount(90, $trend->labels);
        self::assertSame('22.08.', $trend->labels[0]);
        self::assertSame('19.11.', $trend->labels[89]);

        self::assertSame([null, 0, 3, 2], \array_slice($trend->dau, 86), '16.11. not aggregated, 17.11. without requests, today x and y');
        self::assertSame([null, 4, 5, 3], \array_slice($trend->wau, 86), 'today: x, y, z');
        self::assertSame([null, null, 8, 3], \array_slice($trend->mau, 86));
        self::assertSame(6, $trend->mau[59], '20.10.');
        self::assertTrue($trend->hasMonthlyHistory());
    }

    public function testPreviousMauIsTheStoredMauOfTheDay30DaysAgo(): void
    {
        $users = self::getContainer()->get(AnalyticsDashboardService::class)->getActiveUsers();

        self::assertSame(['dau' => 2, 'wau' => 3, 'mau' => 3, 'previousMau' => 6], $users);
    }

    public function testWithoutHistoryThereIsNoPreviousMauAndNoMonthlyLine(): void
    {
        $this->connection->executeStatement('DELETE FROM analytics_active_users_daily');
        $service = self::getContainer()->get(AnalyticsDashboardService::class);

        self::assertNull($service->getActiveUsers()['previousMau']);
        self::assertFalse($service->getUsageTrend()->hasMonthlyHistory(), 'today alone does not count as history');
    }

    private function aggregatedDay(string $date, ?int $dau, ?int $wau, ?int $mau): void
    {
        $this->connection->insert('analytics_aggregation_run', [
            'date' => $date,
            'raw_request_count' => $dau ?? 0,
            'aggregate_rows_written' => 1,
            'aggregated_at' => '2026-11-19 02:15:00',
        ]);
        if (null !== $dau) {
            $this->connection->insert('analytics_uniques_daily', ['date' => $date, 'dau' => $dau, 'distinct_visitors' => $dau, 'distinct_sessions' => $dau]);
        }
        $this->connection->insert('analytics_active_users_daily', ['date' => $date, 'wau' => $wau, 'mau' => $mau]);
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
