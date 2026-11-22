<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DashboardOperationsTest extends WebTestCase
{
    use Factories;

    public function testDashboardShowsOperationsPanel(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'ops-admin-'.bin2hex(random_bytes(4)),
            ]);
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Operations', $text);
        self::assertStringContainsString('Failure queue empty', $crawler->filter('.ea-messenger-card')->text());
        self::assertStringNotContainsString('Queue high', $text, 'the three Messenger cards are replaced by one');
        self::assertStringContainsString('Configured budget', $crawler->filter('.ea-storage-card')->text());
        self::assertStringNotContainsString('Total storage', $text);
        self::assertStringNotContainsString('Imports / media', $text);
        self::assertStringContainsString('Recent notifications', $text);
        self::assertStringContainsString('Healthy', $text);
        self::assertStringNotContainsString('Reminders this month', $text);
        self::assertStringNotContainsString('Storage usage', $text);

        $healthPosition = strpos($text, 'Healthy');
        $kpiPosition = strpos($text, 'Key metrics (last 30 days)');
        self::assertNotFalse($healthPosition);
        self::assertNotFalse($kpiPosition);
        self::assertLessThan($kpiPosition, $healthPosition);

        self::assertSame(
            ['active_users', 'active_hospitals', 'records_imported', 'rejection_rate'],
            $crawler->filter('[data-kpi-card]')->each(static fn (Crawler $card): string => (string) $card->attr('data-kpi-card')),
        );
        self::assertCount(1, $crawler->filter('[data-kpi-card="active_users"] a[href*="/admin/operations/usage-analytics/overview"]'));
        self::assertSame('of 0 participating hospitals', $crawler->filter('[data-kpi-card="active_hospitals"] [data-kpi-detail]')->text());
        self::assertSame('±0 vs. previous 30 days', $crawler->filter('[data-kpi-card="active_hospitals"] [data-kpi-change]')->text());
        self::assertSame('–', $crawler->filter('[data-kpi-card="rejection_rate"] [data-kpi-value]')->text(), 'no rows, no rate');
        self::assertSame('No previous value to compare', $crawler->filter('[data-kpi-card="records_imported"] [data-kpi-change]')->text());
        self::assertSame('No previous value to compare', $crawler->filter('[data-kpi-card="active_users"] [data-kpi-change]')->text(), 'no stored MAU 30 days ago');
        self::assertCount(1, $crawler->filter('[data-controller="admin-kpi-chart"]'));
        self::assertCount(1, $crawler->filter('[data-controller="usage-trend-chart"]'));
        self::assertCount(0, $crawler->filter('[data-controller="admin-ops-chart"]'));
        self::assertCount(1, $crawler->filter('.ea-storage-card'));
        self::assertCount(1, $crawler->filter('.ea-storage-card a[href*="/admin/operations/storage"]'));
        self::assertCount(1, $crawler->filter('.ea-messenger-card a[href*="/admin/operations/messenger"]'));

        $notificationsPosition = strpos($text, 'Recent notifications');
        $operationsPosition = strpos($text, 'Operations');
        self::assertNotFalse($operationsPosition);
        self::assertLessThan($operationsPosition, $kpiPosition, 'key metrics come before operations');
        self::assertLessThan($notificationsPosition, $operationsPosition, 'recent events come last');
    }

    public function testPeriodSwitchSelectsTheKpiPeriodAndKeepsActiveUsersAt30Days(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertSame(['30', '90', '365'], $crawler->filter('[data-kpi-period-switch] a')->each(
            static fn (Crawler $link): string => (string) $link->attr('data-kpi-period'),
        ));
        self::assertSame('30', $crawler->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'));

        $crawler = $client->click($crawler->filter('[data-kpi-period="90"]')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('90', $crawler->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'));
        $text = $crawler->text();
        self::assertStringContainsString('Key metrics (last 90 days)', $text);
        self::assertStringContainsString('Imports per day (last 90 days)', $text);
        self::assertSame('±0 vs. previous 90 days', $crawler->filter('[data-kpi-card="active_hospitals"] [data-kpi-change]')->text());
        self::assertStringContainsString('always 30 days', $crawler->filter('[data-kpi-card="active_users"] [data-kpi-detail]')->text());
        self::assertSame('No previous value to compare', $crawler->filter('[data-kpi-card="active_users"] [data-kpi-change]')->text());

        $client->request(Request::METHOD_GET, '/admin?period=7');
        self::assertSame('30', $client->getCrawler()->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'), 'unknown periods fall back to 30');
    }

    public function testMessengerCardIsHealthyWithEmptyQueues(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.ea-messenger-card');
        self::assertSame('healthy', $card->attr('data-messenger-status'));
        self::assertStringContainsString('Healthy', $card->text());
        self::assertSame('0', $card->filter('[data-messenger-card="pending"]')->text());
        self::assertSame(['high 0', 'low 0', 'mail 0'], $card->filter('[data-messenger-card="queues"] li')->each(
            static fn (Crawler $item): string => trim((string) preg_replace('/\s+/', ' ', $item->text())),
        ));
        self::assertCount(0, $card->filter('[data-messenger-card="oldest"]'));
    }

    public function testMessengerCardFollowsTheBacklogChecksAndCountsReadyMessagesPerQueue(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));
        $utc = new \DateTimeZone('UTC');
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $oldest = new \DateTimeImmutable('2026-01-15 08:10:00', $utc);
        // Two ready messages in low, one in high, one in mail waiting for a retry (not ready) and one failed message.
        foreach ([['low', $oldest, $oldest], ['low', new \DateTimeImmutable('-5 minutes', $utc), null], ['high', new \DateTimeImmutable('-2 minutes', $utc), null], ['mail', new \DateTimeImmutable('-1 minute', $utc), new \DateTimeImmutable('+1 hour', $utc)], ['failed', new \DateTimeImmutable('-1 minute', $utc), null]] as [$queue, $createdAt, $availableAt]) {
            $connection->insert('messenger_messages', [
                'body' => 'x',
                'headers' => '{}',
                'queue_name' => $queue,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'available_at' => ($availableAt ?? $createdAt)->format('Y-m-d H:i:s'),
            ]);
        }

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unhealthy', $crawler->filter('.ea-ops-health-banner')->text());
        $card = $crawler->filter('.ea-messenger-card');
        self::assertSame('unhealthy', $card->attr('data-messenger-status'), 'the card takes the worst Messenger check, like the banner');
        self::assertSame('3', $card->filter('[data-messenger-card="pending"]')->text(), 'the message waiting for a retry and the failed one are not ready');
        self::assertSame(['high 1', 'low 2', 'mail 0'], $card->filter('[data-messenger-card="queues"] li')->each(
            static fn (Crawler $item): string => trim((string) preg_replace('/\s+/', ' ', $item->text())),
        ));
        self::assertSame('Oldest waiting since 15.01.2026 09:10', $card->filter('[data-messenger-card="oldest"]')->text(), 'shown in Europe/Berlin');
        $failed = $card->filter('a[data-messenger-card="failed"]');
        self::assertSame('One message in the failure queue', $failed->text());
        self::assertStringEndsWith('/admin/operations/failed-messages', (string) $failed->attr('href'));
    }

    public function testMessengerCardIsDegradedByFailedMessagesAlone(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('messenger_messages', ['body' => 'x', 'headers' => '{}', 'queue_name' => 'failed', 'created_at' => $now, 'available_at' => $now]);

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        $card = $crawler->filter('.ea-messenger-card');
        self::assertSame('degraded', $card->attr('data-messenger-status'));
        self::assertSame('0', $card->filter('[data-messenger-card="pending"]')->text(), 'the failure queue is not a backlog');
    }

    public function testDashboardShowsTheCompactStorageCardFromTheLatestMeasurement(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        self::getContainer()->get(StorageMeasurementRepositoryInterface::class)->save(new StorageMeasurement(
            measuredOn: new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')),
            measuredAt: new \DateTimeImmutable('-1 hour'),
            durationMs: 1000,
            mode: MeasurementMode::Standalone,
            usedBytes: 3 * 1024 ** 3,
            databaseBytes: 1024 ** 3,
            capacitySource: CapacitySource::Budget,
            capacityBytes: 10 * 1024 ** 3,
            budgetBytes: 10 * 1024 ** 3,
            filesystemTotalBytes: null,
            filesystemFreeBytes: null,
            level: CapacityLevel::Normal,
            items: [
                new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 1024 ** 3),
                new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'vendor', 2 * 1024 ** 3),
            ],
        ));

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.ea-storage-card');
        self::assertCount(1, $card);
        $text = $card->text();
        self::assertStringContainsString('3.00 GB', $text);
        self::assertStringContainsString('of 10.00 GB', $text);
        self::assertStringContainsString('Configured budget', $text);
        self::assertStringContainsString('7.00 GB free', $text);
        self::assertStringContainsString('30 % used', $text);
        self::assertStringNotContainsString('Normal', $text, 'the level is not repeated on the card');
        self::assertStringContainsString('30 % used · Measured', (string) preg_replace('/\s+/', ' ', $card->filter('.ea-storage-card__status')->text()));
        self::assertStringNotContainsString('quota', strtolower($text));
        self::assertCount(2, $card->filter('.ea-storage-bar__segment'));
    }

    public function testHealthCardShowsOnlyTheStorageValueAndLinksToDiskUsage(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $this->store(StorageMeasurementBuilder::measuredAt(
            new \DateTimeImmutable('-1 hour'),
            CapacityLevel::Warning,
            usedBytes: 9 * 1024 ** 3,
        ));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('[data-health-check="storage"]');
        self::assertCount(1, $item);
        self::assertStringEndsWith('/admin/operations/storage', (string) $item->attr('href'));
        self::assertStringContainsString('badge-warning', $this->badgeClass($item));

        self::assertSame('Storage: warning', $item->text());
    }

    public function testMessengerAndSchedulerChecksShowTheirSeverityAndLinkToTheirPages(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));
        $utc = new \DateTimeZone('UTC');
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '{}',
            'queue_name' => 'low',
            'created_at' => new \DateTimeImmutable('-50 minutes', $utc)->format('Y-m-d H:i:s'),
            'available_at' => new \DateTimeImmutable('-50 minutes', $utc)->format('Y-m-d H:i:s'),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unhealthy', $crawler->filter('.ea-ops-health-banner')->text());

        $backlog = $crawler->filter('[data-health-check="messenger_backlog"]');
        self::assertSame('Messenger backlog: oldest message waits 50 min (low)', $backlog->text());
        self::assertStringContainsString('badge-warning', $this->badgeClass($backlog));
        self::assertStringContainsString('/admin/operations/messenger', (string) $backlog->attr('href'));

        $stalled = $crawler->filter('[data-health-check="messenger_stalled"]');
        self::assertStringContainsString('badge-danger', $this->badgeClass($stalled));
        self::assertSame('Messenger processing: 1 message(s) waiting, no handling recorded', $stalled->text());

        self::assertCount(0, $crawler->filter('[data-health-check="messenger_retries"]'), 'checks that are ok are left out while another Messenger check is not');
        self::assertCount(0, $crawler->filter('[data-health-check="messenger_failed"]'));
        self::assertCount(0, $crawler->filter('[data-health-check="messenger"]'));
        $jobs = $crawler->filter('[data-health-check="scheduled_jobs"]');
        self::assertSame('Scheduled jobs: ok', $jobs->text());
        self::assertStringEndsWith('/admin/job-run', (string) $jobs->attr('href'));
    }

    public function testAllMessengerChecksOkShowAsOneItem(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $messenger = $crawler->filter('[data-health-check="messenger"]');
        self::assertCount(1, $messenger);
        self::assertSame('Messenger: ok', $messenger->text());
        self::assertStringContainsString('badge-success', $this->badgeClass($messenger));
        self::assertStringEndsWith('/admin/operations/messenger', (string) $messenger->attr('href'));
        foreach (['messenger_failed', 'messenger_backlog', 'messenger_stalled', 'messenger_retries'] as $key) {
            self::assertCount(0, $crawler->filter(\sprintf('[data-health-check="%s"]', $key)), $key);
        }
        self::assertSame(
            ['database', 'messenger', 'media', 'scheduled_jobs', 'storage'],
            $crawler->filter('[data-health-check]')->each(static fn (Crawler $item): string => (string) $item->attr('data-health-check')),
            'the Messenger item takes the place of the first Messenger check',
        );
    }

    public function testFailedMessagesShowAloneAndLinkToTheFailureQueue(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('-1 hour')));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('messenger_messages', ['body' => 'x', 'headers' => '{}', 'queue_name' => 'failed', 'created_at' => $now, 'available_at' => $now]);

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        $failed = $crawler->filter('[data-health-check="messenger_failed"]');
        self::assertSame('Messenger failed: 1 failed message(s)', $failed->text());
        self::assertStringContainsString('badge-warning', $this->badgeClass($failed));
        self::assertStringContainsString('/admin/operations/failed-messages', (string) $failed->attr('href'));
        self::assertCount(0, $crawler->filter('[data-health-check="messenger"]'));
        self::assertCount(0, $crawler->filter('[data-health-check="messenger_backlog"]'), 'the failure queue is not a backlog');
    }

    public function testWithoutMeasurementHealthIsDegradedAndTheStorageCardIsEmpty(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Degraded', $crawler->filter('.ea-ops-health-banner')->text());
        self::assertSame('Storage: unavailable', $crawler->filter('[data-health-check="storage"]')->text());
        self::assertStringContainsString('No storage measurement yet', $crawler->filter('.ea-storage-card')->text());
    }

    private function store(StorageMeasurement $measurement): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($measurement);
        $entityManager->flush();
    }

    /** The health item is either the badge itself or a link around it. */
    private function badgeClass(Crawler $item): string
    {
        $badge = str_contains((string) $item->attr('class'), 'badge') ? $item : $item->filter('.badge');

        return (string) $badge->attr('class');
    }
}
