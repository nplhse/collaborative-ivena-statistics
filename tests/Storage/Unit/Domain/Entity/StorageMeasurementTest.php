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

    public function testReplacesValuesWithANewerMeasurementOfTheSameDay(): void
    {
        $measurement = $this->measurement(used: 100);
        $newer = $this->measurement(day: '2026-10-25 14:00', used: 900, level: CapacityLevel::Warning);

        $measurement->replaceWith($newer);

        self::assertSame(900, $measurement->getUsedBytes());
        self::assertSame(CapacityLevel::Warning, $measurement->getLevel());
        self::assertSame('2026-10-25 14:00', $measurement->getMeasuredAt()->format('Y-m-d H:i'));
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
            filesystemTotalBytes: null,
            filesystemFreeBytes: null,
            level: $level,
            items: $items,
        );
    }
}
