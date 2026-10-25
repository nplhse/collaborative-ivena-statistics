<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
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
        $this->store(StorageMeasurementBuilder::create(new \DateTimeImmutable('-1 hour')));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Operations', $text);
        self::assertStringContainsString('Failed messages', $text);
        self::assertStringContainsString('Total storage', $text);
        self::assertStringContainsString('Database', $text);
        self::assertStringContainsString('Imports / media', $text);
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
        self::assertGreaterThan(0, $crawler->filter('.ea-ops-card-trend')->count());
    }

    public function testHealthCardShowsStorageDetailsAndLinksToDiskUsage(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $this->store(StorageMeasurementBuilder::create(
            new \DateTimeImmutable('2026-10-25 03:30'),
            CapacityLevel::Warning,
            usedBytes: 9 * 1024 ** 3,
        ));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('[data-health-check="storage"]');
        self::assertCount(1, $item);
        self::assertSame('/admin', $item->attr('href'));
        self::assertStringContainsString('bg-warning', (string) $item->attr('class'));

        $text = $item->text();
        self::assertStringContainsString('Storage:', $text);
        self::assertStringContainsString('9.00 GB of 10.00 GB (budget)', $text);
        self::assertStringContainsString('10 % free', $text);
        self::assertStringContainsString('Level: warning', $text);
        self::assertStringContainsString('measured 2026-10-25 03:30', $text);
    }

    public function testHealthCardShowsMissingStorageMeasurement(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Degraded', $crawler->filter('.ea-ops-health-banner')->text());
        self::assertSame('Storage: unavailable', $crawler->filter('[data-health-check="storage"]')->text());
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
