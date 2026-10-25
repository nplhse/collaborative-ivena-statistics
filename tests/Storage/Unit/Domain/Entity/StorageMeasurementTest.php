<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Domain\Entity;

use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\TestCase;

final class StorageMeasurementTest extends TestCase
{
    public function testNormalizesTheDayAndKeepsItems(): void
    {
        $item = new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 300);
        $measurement = $this->measurement(day: '2026-10-25 03:30:12', items: [$item]);

        self::assertSame('2026-10-25 00:00:00', $measurement->getMeasuredOn()->format('Y-m-d H:i:s'));
        self::assertEquals([$item], $measurement->getItems());
    }

    public function testExposesEveryValue(): void
    {
        $measurement = $this->measurement();

        self::assertNull($measurement->getId());
        self::assertSame('2026-10-25 03:30', $measurement->getMeasuredAt()->format('Y-m-d H:i'));
        self::assertSame(1200, $measurement->getDurationMs());
        self::assertSame(MeasurementMode::Standalone, $measurement->getMode());
        self::assertSame(100, $measurement->getUsedBytes());
        self::assertSame(40, $measurement->getDatabaseBytes());
        self::assertSame(CapacitySource::Budget, $measurement->getCapacitySource());
        self::assertSame(1000, $measurement->getCapacityBytes());
        self::assertSame(1000, $measurement->getBudgetBytes());
        self::assertSame(5000, $measurement->getFilesystemTotalBytes());
        self::assertSame(2500, $measurement->getFilesystemFreeBytes());
        self::assertSame(CapacityLevel::Normal, $measurement->getLevel());
        self::assertSame([], $measurement->getItems());
    }

    public function testReplacesEveryValueWithANewerMeasurementOfTheSameDay(): void
    {
        $measurement = $this->measurement();
        $item = new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 700, locationDetail: 'var/imports');
        $newer = new StorageMeasurement(
            measuredOn: new \DateTimeImmutable('2026-10-25 14:00'),
            measuredAt: new \DateTimeImmutable('2026-10-25 14:00'),
            durationMs: 9000,
            mode: MeasurementMode::Deployer,
            usedBytes: 4800,
            databaseBytes: 300,
            capacitySource: CapacitySource::Filesystem,
            capacityBytes: 5000,
            budgetBytes: null,
            filesystemTotalBytes: 5000,
            filesystemFreeBytes: 200,
            level: CapacityLevel::Critical,
            items: [$item],
        );

        $measurement->replaceWith($newer);

        self::assertSame('2026-10-25 00:00', $measurement->getMeasuredOn()->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 14:00', $measurement->getMeasuredAt()->format('Y-m-d H:i'));
        self::assertSame(9000, $measurement->getDurationMs());
        self::assertSame(MeasurementMode::Deployer, $measurement->getMode());
        self::assertSame(4800, $measurement->getUsedBytes());
        self::assertSame(300, $measurement->getDatabaseBytes());
        self::assertSame(CapacitySource::Filesystem, $measurement->getCapacitySource());
        self::assertSame(5000, $measurement->getCapacityBytes());
        self::assertNull($measurement->getBudgetBytes());
        self::assertSame(5000, $measurement->getFilesystemTotalBytes());
        self::assertSame(200, $measurement->getFilesystemFreeBytes());
        self::assertSame(CapacityLevel::Critical, $measurement->getLevel());
        self::assertEquals([$item], $measurement->getItems());
    }

    public function testDoesNotReplaceWithAnotherDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement()->replaceWith($this->measurement(day: '2026-10-26 03:30'));
    }

    public function testRequiresACapacityForAReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement(source: CapacitySource::Budget, capacity: null);
    }

    public function testRejectsACapacityWithoutReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement(source: CapacitySource::None, capacity: 1000);
    }

    public function testRejectsAZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement(capacity: 0);
    }

    public function testRequiresUnknownLevelWithoutUsage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement(used: null, level: CapacityLevel::Normal);
    }

    public function testAcceptsMissingUsageWithUnknownLevel(): void
    {
        $measurement = $this->measurement(used: null, level: CapacityLevel::Unknown);

        self::assertNull($measurement->getUsedBytes());
    }

    public function testRejectsNegativeSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->measurement(used: -1);
    }

    /**
     * @param list<StorageMeasurementItem> $items
     */
    private function measurement(
        string $day = '2026-10-25 03:30',
        ?int $used = 100,
        CapacitySource $source = CapacitySource::Budget,
        ?int $capacity = 1000,
        CapacityLevel $level = CapacityLevel::Normal,
        array $items = [],
    ): StorageMeasurement {
        $at = new \DateTimeImmutable($day);

        return new StorageMeasurement(
            measuredOn: $at,
            measuredAt: $at,
            durationMs: 1200,
            mode: MeasurementMode::Standalone,
            usedBytes: $used,
            databaseBytes: 40,
            capacitySource: $source,
            capacityBytes: $capacity,
            budgetBytes: $capacity,
            filesystemTotalBytes: 5000,
            filesystemFreeBytes: 2500,
            level: $level,
            items: $items,
        );
    }
}
