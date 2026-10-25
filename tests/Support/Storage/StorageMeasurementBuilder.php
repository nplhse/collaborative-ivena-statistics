<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;

/**
 * Builds stored storage measurements for health and dashboard tests.
 */
final class StorageMeasurementBuilder
{
    public static function create(
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
