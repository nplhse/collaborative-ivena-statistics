<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Measurement;

use App\Storage\Application\Measurement\StorageUsage;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageUsageTest extends TestCase
{
    public function testABudgetIsComparedWithTheMeasuredUsage(): void
    {
        $usage = StorageUsage::fromMeasurement(StorageMeasurementBuilder::create(usedBytes: 853));

        self::assertNotNull($usage);
        self::assertSame(CapacitySource::Budget, $usage->source);
        self::assertSame(853, $usage->usedBytes);
        self::assertSame(1000, $usage->capacityBytes);
        self::assertSame(85.3, $usage->usedPercent());
        self::assertSame(147, $usage->remainingBytes());
        self::assertSame(14.7, $usage->remainingPercent());
    }

    public function testTheFilesystemIsComparedWithItsWholeUsage(): void
    {
        $usage = StorageUsage::fromMeasurement(StorageMeasurementBuilder::create(
            usedBytes: 100,
            level: CapacityLevel::Warning,
            source: CapacitySource::Filesystem,
            capacityBytes: 5000,
            filesystemTotalBytes: 5000,
            filesystemFreeBytes: 400,
        ));

        self::assertNotNull($usage);
        self::assertSame(4600, $usage->usedBytes);
        self::assertSame(92.0, $usage->usedPercent());
        self::assertSame(400, $usage->remainingBytes());
    }

    public function testNothingRemainsWhenTheCapacityIsExceeded(): void
    {
        $usage = new StorageUsage(CapacitySource::Budget, 1200, 1000);

        self::assertSame(120.0, $usage->usedPercent());
        self::assertSame(0, $usage->remainingBytes());
        self::assertSame(0.0, $usage->remainingPercent());
    }

    public function testThereIsNoUsageWithoutAReferenceOrAComparedValue(): void
    {
        self::assertNull(StorageUsage::fromMeasurement(StorageMeasurementBuilder::create(level: CapacityLevel::Unknown, source: CapacitySource::None, capacityBytes: null)));
        self::assertNull(StorageUsage::fromMeasurement(StorageMeasurementBuilder::create(usedBytes: null, level: CapacityLevel::Unknown)));
        self::assertNull(StorageUsage::fromMeasurement(StorageMeasurementBuilder::create(source: CapacitySource::Filesystem, capacityBytes: 5000, filesystemTotalBytes: 5000)));
    }

    /**
     * @return iterable<string, array{CapacitySource, int, int}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no source' => [CapacitySource::None, 1, 1000];
        yield 'no capacity' => [CapacitySource::Budget, 1, 0];
        yield 'negative usage' => [CapacitySource::Budget, -1, 1000];
    }

    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidValues(CapacitySource $source, int $used, int $capacity): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorageUsage($source, $used, $capacity);
    }
}
