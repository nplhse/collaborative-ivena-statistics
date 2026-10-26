<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
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
        self::assertStringContainsString('Failed messages', $text);
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

        self::assertCount(1, $crawler->filter('[data-controller="admin-kpi-chart"]'));
        self::assertCount(0, $crawler->filter('[data-controller="admin-ops-chart"]'));
        self::assertCount(1, $crawler->filter('.ea-storage-card'));
        self::assertCount(1, $crawler->filter('.ea-storage-card a[href*="/admin/operations/storage"]'));
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
        self::assertStringContainsString('bg-warning', (string) $item->attr('class'));

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
        self::assertStringContainsString('bg-warning', (string) $backlog->attr('class'));
        self::assertStringContainsString('/admin/operations/messenger', (string) $backlog->attr('href'));

        $stalled = $crawler->filter('[data-health-check="messenger_stalled"]');
        self::assertStringContainsString('bg-danger', (string) $stalled->attr('class'));
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
        self::assertStringContainsString('bg-success', (string) $messenger->attr('class'));
        self::assertStringEndsWith('/admin/operations/messenger', (string) $messenger->attr('href'));
        foreach (['messenger_failed', 'messenger_backlog', 'messenger_stalled', 'messenger_retries'] as $key) {
            self::assertCount(0, $crawler->filter(\sprintf('[data-health-check="%s"]', $key)), $key);
        }
        self::assertSame(
            ['database', 'messenger', 'scheduled_jobs', 'storage'],
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
        self::assertStringContainsString('bg-warning', (string) $failed->attr('class'));
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

    public function testFailedImportsDashboardShows30DayFilterAndViewAllLink(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::FAILED,
            'name' => 'Recent failed import',
            'createdAt' => new \DateTimeImmutable('-5 days'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::FAILED,
            'name' => 'Old failed import',
            'createdAt' => new \DateTimeImmutable('-40 days'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'name' => 'Successful import',
            'createdAt' => new \DateTimeImmutable('-2 days'),
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Last 30 days', $crawler->text());
        self::assertStringContainsString('All failed imports', $crawler->text());
        self::assertStringContainsString('Recent failed import', $crawler->text());
        self::assertStringNotContainsString('Old failed import', $crawler->text());

        $badge = $this->findFailedImportsBadge($crawler);
        self::assertNotNull($badge);
        self::assertSame('2', trim($badge->text()));
    }

    private function store(StorageMeasurement $measurement): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($measurement);
        $entityManager->flush();
    }

    private function findFailedImportsBadge(Crawler $crawler): ?Crawler
    {
        $link = $crawler->filter('a')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), 'All failed imports'),
        );

        if (0 === $link->count()) {
            return null;
        }

        $badge = $link->filter('.ea-admin-primary-badge-btn__badge');
        if (0 === $badge->count()) {
            return null;
        }

        return $badge;
    }
}
