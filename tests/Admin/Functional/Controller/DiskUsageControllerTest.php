<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DiskUsageControllerTest extends WebTestCase
{
    use Factories;

    public function testShowsAHintBeforeTheFirstMeasurement(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/storage');

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Disk usage', $text);
        self::assertStringContainsString('No storage measurement yet', $text);
        self::assertStringContainsString('bin/console app:storage:analyze --persist', $text);
        self::assertCount(0, $crawler->filter('[data-controller="storage-history-chart"]'));
        self::assertGreaterThan(0, $crawler->filter('a[href*="/admin/operations/storage"]')->count(), 'the System menu links to the page');
    }

    public function testShowsTheLatestMeasurementWithCategoriesLocationsCapacityAndHistory(): void
    {
        $client = $this->adminClient();
        $repository = self::getContainer()->get(StorageMeasurementRepositoryInterface::class);
        $repository->save($this->measurement(new \DateTimeImmutable('-8 days'), 800_000_000));
        $repository->save($this->measurement(new \DateTimeImmutable('-1 day'), 900_000_000));

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/storage');

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        foreach (['Configured budget', 'Deployer', 'Categories', 'Database', 'Imports', 'Media', 'Application', 'Temporary', 'Other',
            'PHP dependencies (vendor)', 'Current release', 'Previous release', 'Shared directory', 'Leftover data',
            'Filesystem', 'Largest database tables', 'History', 'Open imports', 'Open media library', 'Normal'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }

        self::assertStringContainsString('20261024', $text, 'release names are shown');
        self::assertStringContainsString('+95.4 MB', $text, 'database growth since the week before');
        self::assertStringNotContainsString('Outdated', $text);

        $chart = $crawler->filter('[data-controller="storage-history-chart"]');
        self::assertCount(1, $chart);
        $value = json_decode((string) $chart->attr('data-storage-history-chart-chart-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertCount(2, $value['days']);
        self::assertCount(6, $value['series']);
        self::assertSame([5_000_000_000, 5_000_000_000], $value['capacity']);

        self::assertCount(10, $crawler->filter('.ea-storage-tables tbody tr'), 'the ten largest tables are read live');
    }

    public function testMarksAnOutdatedMeasurement(): void
    {
        $client = $this->adminClient();
        self::getContainer()->get(StorageMeasurementRepositoryInterface::class)->save($this->measurement(new \DateTimeImmutable('-3 days'), 1_000));

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/storage');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Outdated', $crawler->text());
        self::assertStringContainsString('older than 36 hours', $crawler->text());
    }

    public function testIsOnlyForAdministrators(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/admin/operations/storage');

        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function measurement(\DateTimeImmutable $at, int $databaseBytes): StorageMeasurement
    {
        $items = [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300_000_000),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Media, 'media_uploads', 200_000_000),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 150_000_000, locationDetail: '20261024'),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Temporary, 'var_cache', 50_000_000, locationDetail: '20261024'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 150_000_000, locationDetail: '20261020'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Media, 'media_uploads', 20_000_000, locationDetail: '20261020', flags: [MeasurementItemFlag::LeftoverInRelease]),
            new StorageMeasurementItem(StorageLocation::DeployRoot, StorageCategory::Other, 'deploy_metadata', 1_000),
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', $databaseBytes),
        ];

        return new StorageMeasurement(
            measuredOn: $at->setTimezone(new \DateTimeZone('Europe/Berlin')),
            measuredAt: $at,
            durationMs: 4200,
            mode: MeasurementMode::Deployer,
            usedBytes: 870_001_000 + $databaseBytes,
            databaseBytes: $databaseBytes,
            capacitySource: CapacitySource::Budget,
            capacityBytes: 5_000_000_000,
            budgetBytes: 5_000_000_000,
            filesystemTotalBytes: 100_000_000_000,
            filesystemFreeBytes: 40_000_000_000,
            level: CapacityLevel::Normal,
            items: $items,
        );
    }
}
