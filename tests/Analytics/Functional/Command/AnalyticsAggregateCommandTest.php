<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Functional\Command;

use App\Analytics\Application\Service\AnalyticsDailyAggregationService;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Analytics\UI\Console\Command\AnalyticsAggregateCommand;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyticsAggregateCommandTest extends DatabaseKernelTestCase
{
    public function testCommandAggregatesGivenDateIdempotently(): void
    {
        self::bootKernel();
        $day = new \DateTimeImmutable('2026-05-10 14:00:00', AnalyticsCalendar::timezone());
        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $repository->save(new AnalyticsRequest(
            occurredAt: $day,
            routeName: 'app_stats_dashboard',
            featureArea: FeatureArea::Dashboard,
            httpStatus: 200,
            durationMs: 100,
            dbQueryCount: 2,
            dbTimeMs: 40,
            isAuthenticated: true,
            userRole: 'ROLE_PARTICIPANT',
            analyticsUserKey: 'cmd-user',
            visitorKey: 'cmd-visitor',
            sessionKey: 'cmd-session',
            browserFamily: BrowserFamily::Chrome,
            deviceType: DeviceType::Desktop,
            queryParamNames: [],
        ));

        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--date' => '2026-05-10', '--no-cleanup' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Analytics aggregation finished:', $tester->getDisplay());
        self::assertStringContainsString('(2026-05-10)', $tester->getDisplay());

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_aggregation_run WHERE date = :date',
            ['date' => '2026-05-10'],
        ));

        $second = $tester->execute(['--date' => '2026-05-10', '--no-cleanup' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $second);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_aggregation_run WHERE date = :date',
            ['date' => '2026-05-10'],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request_daily WHERE date = :date',
            ['date' => '2026-05-10'],
        ));
    }

    public function testCommandRejectsInvalidDate(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--date' => 'not-a-date']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --date', $tester->getDisplay());
    }

    public function testDryRunWithoutCleanupOnlyWarnsThatAggregationStillWrites(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--date' => '2026-05-10', '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('--dry-run only previews cleanup', $tester->getDisplay());
    }

    public function testCleanupOnlyDryRunDoesNotDeleteRawRows(): void
    {
        self::bootKernel();
        $expired = AnalyticsCalendar::daysAgo(40)->setTime(10, 0);
        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $repository->save(new AnalyticsRequest(
            occurredAt: $expired,
            routeName: 'app_home',
            featureArea: FeatureArea::Home,
            httpStatus: 200,
            durationMs: 80,
            dbQueryCount: 1,
            dbTimeMs: 20,
            isAuthenticated: false,
            userRole: null,
            analyticsUserKey: null,
            visitorKey: 'dry-visitor',
            sessionKey: 'dry-session',
            browserFamily: BrowserFamily::Safari,
            deviceType: DeviceType::Mobile,
            queryParamNames: [],
        ));
        self::getContainer()->get(AnalyticsDailyAggregationService::class)->aggregateForDate($expired);

        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--cleanup-only' => true, '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('would delete', $tester->getDisplay());

        $connection = self::getContainer()->get(Connection::class);
        [$from, $to] = AnalyticsCalendar::dayBounds($expired);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE occurred_at >= :from AND occurred_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        ));
    }

    public function testCommandRejectsConflictingCleanupOptions(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute(['--cleanup-only' => true, '--date' => '2026-05-10']));
        self::assertStringContainsString('--cleanup-only option cannot be combined with --date', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--cleanup-only' => true, '--no-cleanup' => true]));
        self::assertStringContainsString('--cleanup-only and --no-cleanup', $tester->getDisplay());
    }

    public function testCommandAggregatesMultipleDaysAndSummarizesPeriod(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--days' => '2', '--no-cleanup' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('2 day(s)', $tester->getDisplay());
        self::assertStringContainsString('…', $tester->getDisplay());
        self::assertStringContainsString('No raw analytics rows in the selected period.', $tester->getDisplay());
    }

    public function testCommandRejectsInvalidDaysOption(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute(['--days' => '0', '--no-cleanup' => true]));
        self::assertStringContainsString('The --days option must be between 1 and 366.', $tester->getDisplay());
    }

    public function testCleanupOnlyDeletesExpiredAggregatedRawRows(): void
    {
        self::bootKernel();
        $expired = AnalyticsCalendar::daysAgo(40)->setTime(10, 0);
        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $repository->save(new AnalyticsRequest(
            occurredAt: $expired,
            routeName: 'app_home',
            featureArea: FeatureArea::Home,
            httpStatus: 200,
            durationMs: 80,
            dbQueryCount: 1,
            dbTimeMs: 20,
            isAuthenticated: false,
            userRole: null,
            analyticsUserKey: null,
            visitorKey: 'purge-visitor',
            sessionKey: 'purge-session',
            browserFamily: BrowserFamily::Safari,
            deviceType: DeviceType::Mobile,
            queryParamNames: [],
        ));
        self::getContainer()->get(AnalyticsDailyAggregationService::class)->aggregateForDate($expired);

        $command = self::getContainer()->get(AnalyticsAggregateCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--cleanup-only' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('deleted 1 request(s)', $tester->getDisplay());

        $connection = self::getContainer()->get(Connection::class);
        [$from, $to] = AnalyticsCalendar::dayBounds($expired);
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE occurred_at >= :from AND occurred_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        ));
    }
}
