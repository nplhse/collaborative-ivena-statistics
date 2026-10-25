<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Stored-looking storage measurements: create() for notification tests (a budget of 1000 bytes by default),
 * measuredAt() for health and dashboard tests that depend on the measurement time.
 */
final class StorageMeasurementBuilder
{
    /**
     * @param list<StorageMeasurementItem> $items
     * @param int|null                     $id    sets the id as Doctrine would
     */
    public static function create(
        string $day = '2026-10-25',
        ?int $usedBytes = 850,
        CapacityLevel $level = CapacityLevel::Notice,
        CapacitySource $source = CapacitySource::Budget,
        ?int $capacityBytes = 1000,
        ?int $databaseBytes = 200,
        ?int $filesystemTotalBytes = null,
        ?int $filesystemFreeBytes = null,
        MeasurementMode $mode = MeasurementMode::Standalone,
        array $items = [],
        ?int $id = null,
    ): StorageMeasurement {
        $measurement = new StorageMeasurement(
            measuredOn: new \DateTimeImmutable($day),
            measuredAt: new \DateTimeImmutable($day.' 03:30'),
            durationMs: 1000,
            mode: $mode,
            usedBytes: $usedBytes,
            databaseBytes: $databaseBytes,
            capacitySource: $source,
            capacityBytes: $capacityBytes,
            budgetBytes: CapacitySource::Budget === $source ? $capacityBytes : null,
            filesystemTotalBytes: $filesystemTotalBytes,
            filesystemFreeBytes: $filesystemFreeBytes,
            level: $level,
            items: $items,
        );

        if (null !== $id) {
            new \ReflectionProperty(StorageMeasurement::class, 'id')->setValue($measurement, $id);
        }

        return $measurement;
    }

    /**
     * A measurement taken at the given time, with realistic sizes (4 GB of a 10 GB budget by default).
     */
    public static function measuredAt(
        \DateTimeImmutable $measuredAt,
        CapacityLevel $level = CapacityLevel::Normal,
        ?int $usedBytes = 4 * 1024 ** 3,
        CapacitySource $source = CapacitySource::Budget,
        ?int $capacityBytes = 10 * 1024 ** 3,
    ): StorageMeasurement {
        return new StorageMeasurement(
            measuredOn: $measuredAt,
            measuredAt: $measuredAt,
            durationMs: 1200,
            mode: MeasurementMode::Standalone,
            usedBytes: $usedBytes,
            databaseBytes: null === $usedBytes ? null : 1024 ** 3,
            capacitySource: $source,
            capacityBytes: CapacitySource::None === $source ? null : $capacityBytes,
            budgetBytes: CapacitySource::Budget === $source ? $capacityBytes : null,
            filesystemTotalBytes: null,
            filesystemFreeBytes: null,
            level: $level,
            items: [],
        );
    }
}
