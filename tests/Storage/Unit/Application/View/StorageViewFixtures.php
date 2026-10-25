<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\View;

use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Builds measurements for the view tests without a database.
 */
final class StorageViewFixtures
{
    public static function settings(int $budgetBytes = 1000, string $source = 'budget', int $maxAgeHours = 36): StorageSettings
    {
        return new StorageSettings(80, 90, 99, $budgetBytes, $source, $maxAgeHours);
    }

    /**
     * @param list<StorageMeasurementItem> $items
     * @param int|false|null               $used  false sums the items
     */
    public static function measurement(
        string $day = '2026-10-25',
        array $items = [],
        int|false|null $used = false,
        CapacitySource $source = CapacitySource::Budget,
        ?int $capacity = 1000,
        ?int $budget = 1000,
        ?int $filesystemTotal = 10_000,
        ?int $filesystemFree = 4_000,
        CapacityLevel $level = CapacityLevel::Normal,
        MeasurementMode $mode = MeasurementMode::Deployer,
        ?int $database = null,
    ): StorageMeasurement {
        $sum = 0;
        foreach ($items as $item) {
            $sum += $item->bytes ?? 0;
        }

        return new StorageMeasurement(
            measuredOn: new \DateTimeImmutable($day.' 03:30', new \DateTimeZone('Europe/Berlin')),
            measuredAt: new \DateTimeImmutable($day.' 03:30', new \DateTimeZone('Europe/Berlin')),
            durationMs: 1500,
            mode: $mode,
            usedBytes: false === $used ? $sum : $used,
            databaseBytes: $database,
            capacitySource: $source,
            capacityBytes: CapacitySource::None === $source ? null : $capacity,
            budgetBytes: $budget,
            filesystemTotalBytes: $filesystemTotal,
            filesystemFreeBytes: $filesystemFree,
            level: $level,
            items: $items,
        );
    }
}
