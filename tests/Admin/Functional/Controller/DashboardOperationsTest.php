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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Wiring of the operations panel on the admin dashboard. The health items are mapped in HealthCheckItemMapperTest,
 * the Messenger card numbers come from OperationalDashboardServiceTest, the health checks themselves from
 * tests/Shared/Integration/Monitoring/MessengerHealthChecksTest.
 */
#[ResetDatabase]
final class DashboardOperationsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2026-11-13 12:30:00 UTC';

    public function testDashboardShowsOperationsPanel(): void
    {
        $client = $this->adminClient();
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('2026-11-13 11:30:00 UTC')));

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
        self::assertCount(1, $crawler->filter('[data-testid="sign-in-security-card"] a[href*="/admin/operations/sign-in-security"]'));

        $notificationsPosition = strpos($text, 'Recent notifications');
        $operationsPosition = strpos($text, 'Operations');
        self::assertNotFalse($operationsPosition);
        self::assertLessThan($operationsPosition, $kpiPosition, 'key metrics come before operations');
        self::assertLessThan($notificationsPosition, $operationsPosition, 'recent events come last');
    }

    public function testThePeriodSwitchOffersThreePeriodsAndStartsWith30Days(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertSame(['30', '90', '365'], $crawler->filter('[data-kpi-period-switch] a')->each(
            static fn (Crawler $link): string => (string) $link->attr('data-kpi-period'),
        ));
        self::assertSame('30', $crawler->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'));
    }

    public function testAChosenPeriodSetsTheKpisAndKeepsActiveUsersAt30Days(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin?period=90');

        self::assertResponseIsSuccessful();
        self::assertSame('90', $crawler->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'));
        $text = $crawler->text();
        self::assertStringContainsString('Key metrics (last 90 days)', $text);
        self::assertStringContainsString('Imports per day (last 90 days)', $text);
        self::assertSame('±0 vs. previous 90 days', $crawler->filter('[data-kpi-card="active_hospitals"] [data-kpi-change]')->text());
        self::assertStringContainsString('always 30 days', $crawler->filter('[data-kpi-card="active_users"] [data-kpi-detail]')->text());
        self::assertSame('No previous value to compare', $crawler->filter('[data-kpi-card="active_users"] [data-kpi-change]')->text());
    }

    public function testAnUnknownPeriodFallsBackTo30Days(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin?period=7');

        self::assertSame('30', $crawler->filter('[data-kpi-period-switch] a[aria-current]')->attr('data-kpi-period'));
    }

    public function testTheMessengerCardShowsTheStatusTheQueuesTheOldestMessageAndTheFailureQueue(): void
    {
        $client = $this->adminClient();
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('2026-11-13 11:30:00 UTC')));
        // Two ready messages in low (the older one waits 2 h, so the backlog check is unhealthy), one in high, one failed.
        $this->queued('low', '2026-11-13 10:30:00');
        $this->queued('low', '2026-11-13 12:25:00');
        $this->queued('high', '2026-11-13 12:28:00');
        $this->queued('failed', '2026-11-13 12:29:00');

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.ea-messenger-card');
        self::assertSame('unhealthy', $card->attr('data-messenger-status'), 'the card takes the worst Messenger check, like the banner');
        self::assertSame('3', $card->filter('[data-messenger-card="pending"]')->text());
        self::assertSame(['high 1', 'low 2', 'mail 0'], $card->filter('[data-messenger-card="queues"] li')->each(
            static fn (Crawler $item): string => trim((string) preg_replace('/\s+/', ' ', $item->text())),
        ));
        self::assertSame('Oldest waiting since 13.11.2026 11:30', $card->filter('[data-messenger-card="oldest"]')->text(), 'shown in Europe/Berlin');
        $failed = $card->filter('a[data-messenger-card="failed"]');
        self::assertSame('One message in the failure queue', $failed->text());
        self::assertStringEndsWith('/admin/operations/failed-messages', (string) $failed->attr('href'));
    }

    public function testDashboardShowsTheCompactStorageCardFromTheLatestMeasurement(): void
    {
        $client = $this->adminClient();

        self::getContainer()->get(StorageMeasurementRepositoryInterface::class)->save(new StorageMeasurement(
            measuredOn: new \DateTimeImmutable('2026-11-13', new \DateTimeZone('Europe/Berlin')),
            measuredAt: new \DateTimeImmutable('2026-11-13 11:30:00 UTC'),
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

    public function testTheHealthCardShowsEveryCheckWithItsBadgeAndLink(): void
    {
        $client = $this->adminClient();
        $this->store(StorageMeasurementBuilder::measuredAt(new \DateTimeImmutable('2026-11-13 11:30:00 UTC')));

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['database', 'messenger', 'media', 'research_export', 'maintenance', 'scheduled_jobs', 'storage', 'two_factor'],
            $crawler->filter('[data-health-check]')->each(static fn (Crawler $item): string => (string) $item->attr('data-health-check')),
            'all Messenger checks are ok, so they show as one item',
        );
        $messenger = $crawler->filter('[data-health-check="messenger"]');
        self::assertSame('Messenger: ok', $messenger->text());
        self::assertStringContainsString('badge-success', $this->badgeClass($messenger));
        self::assertStringEndsWith('/admin/operations/messenger', (string) $messenger->attr('href'));
        self::assertStringEndsWith('/admin/job-run', (string) $crawler->filter('[data-health-check="scheduled_jobs"]')->attr('href'));
        $storage = $crawler->filter('[data-health-check="storage"]');
        self::assertSame('Storage: ok', $storage->text(), 'the measurement of an hour ago is fresh');
        self::assertStringEndsWith('/admin/operations/storage', (string) $storage->attr('href'));
    }

    public function testWithoutMeasurementHealthIsDegradedAndTheStorageCardIsEmpty(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Degraded', $crawler->filter('.ea-ops-health-banner')->text());
        self::assertSame('Storage: unavailable', $crawler->filter('[data-health-check="storage"]')->text());
        self::assertStringContainsString('No storage measurement yet', $crawler->filter('.ea-storage-card')->text());
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        self::mockTime(self::NOW);
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    /** Doctrine stores the queue's times in UTC without a zone. */
    private function queued(string $queue, string $createdAt): void
    {
        self::getContainer()->get(Connection::class)->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => $createdAt,
            'available_at' => $createdAt,
        ]);
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
