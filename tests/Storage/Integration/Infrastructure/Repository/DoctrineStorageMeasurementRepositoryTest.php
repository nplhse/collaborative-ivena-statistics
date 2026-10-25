<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Repository;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\Storage\Infrastructure\Repository\DoctrineStorageMeasurementRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineStorageMeasurementRepositoryTest extends DatabaseKernelTestCase
{
    private StorageMeasurementRepositoryInterface $repository;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineStorageMeasurementRepository($this->entityManager);
    }

    public function testStoresAndReadsAMeasurementWithItemsAndLargeSizes(): void
    {
        $items = [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 3 * 1024 ** 3, locationDetail: 'var/imports'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Media, 'public_uploads_media', 512, locationDetail: '41', flags: [MeasurementItemFlag::LeftoverInRelease]),
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed),
        ];

        $saved = $this->repository->save($this->measurement('2026-10-25', used: 12 * 1024 ** 3, items: $items));
        $this->entityManager->clear();

        $loaded = $this->repository->findForDay(new \DateTimeImmutable('2026-10-25 17:45'));

        self::assertNotNull($loaded);
        self::assertSame($saved->getId(), $loaded->getId());
        self::assertSame(12 * 1024 ** 3, $loaded->getUsedBytes());
        self::assertSame(CapacitySource::Budget, $loaded->getCapacitySource());
        self::assertSame(MeasurementMode::Deployer, $loaded->getMode());
        self::assertEquals($items, $loaded->getItems());
    }

    public function testASecondMeasurementOfTheSameDayReplacesTheFirst(): void
    {
        $first = $this->repository->save($this->measurement('2026-10-25', used: 100));
        $this->entityManager->clear();

        $second = $this->repository->save($this->measurement('2026-10-25', used: 950, level: CapacityLevel::Warning, at: '2026-10-25 14:10'));

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM storage_measurement'));

        $this->entityManager->clear();
        $loaded = $this->repository->findForDay(new \DateTimeImmutable('2026-10-25'));
        self::assertNotNull($loaded);
        self::assertSame(950, $loaded->getUsedBytes());
        self::assertSame(CapacityLevel::Warning, $loaded->getLevel());
        self::assertSame('14:10', $loaded->getMeasuredAt()->format('H:i'));
    }

    public function testHasNothingBeforeTheFirstMeasurement(): void
    {
        self::assertNull($this->repository->latest());
        self::assertSame([], $this->repository->findSince(new \DateTimeImmutable('2026-01-01')));
    }

    public function testReturnsTheLatestAndAHistoryOldestFirst(): void
    {
        foreach (['2026-10-23', '2026-10-25', '2026-10-20', '2026-10-24'] as $day) {
            $this->repository->save($this->measurement($day));
        }
        $this->entityManager->clear();

        $latest = $this->repository->latest();
        self::assertInstanceOf(StorageMeasurement::class, $latest);
        self::assertSame('2026-10-25', $latest->getMeasuredOn()->format('Y-m-d'));
        self::assertSame(
            ['2026-10-23', '2026-10-24', '2026-10-25'],
            array_map(
                static fn (StorageMeasurement $measurement): string => $measurement->getMeasuredOn()->format('Y-m-d'),
                $this->repository->findSince(new \DateTimeImmutable('2026-10-23 12:00')),
            ),
        );
        self::assertNull($this->repository->findForDay(new \DateTimeImmutable('2026-10-21')));
    }

    /**
     * @param list<StorageMeasurementItem> $items
     */
    private function measurement(
        string $day,
        ?int $used = 100,
        CapacityLevel $level = CapacityLevel::Normal,
        ?string $at = null,
        array $items = [],
    ): StorageMeasurement {
        return new StorageMeasurement(
            measuredOn: new \DateTimeImmutable($day),
            measuredAt: new \DateTimeImmutable($at ?? $day.' 03:30'),
            durationMs: 4200,
            mode: MeasurementMode::Deployer,
            usedBytes: $used,
            databaseBytes: 2048,
            capacitySource: CapacitySource::Budget,
            capacityBytes: 20 * 1024 ** 3,
            budgetBytes: 20 * 1024 ** 3,
            filesystemTotalBytes: 500 * 1024 ** 3,
            filesystemFreeBytes: 120 * 1024 ** 3,
            level: $level,
            items: $items,
        );
    }
}
