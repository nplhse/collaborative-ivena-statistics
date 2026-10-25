<?php

declare(strict_types=1);

namespace App\Storage\Application\Notification;

use App\Storage\Application\ByteFormatter;
use App\Storage\Application\Measurement\StorageBreakdown;
use App\Storage\Application\Measurement\StorageTotal;
use App\Storage\Application\Measurement\StorageUsage;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageLocation;

/**
 * Translation parameters of the storage notifications (ADR 017, "Notifications"), built from the stored
 * measurement only. Sizes are passed formatted, codes (level, source, category) as select keys.
 *
 * `reference` is budget or filesystem when the remaining capacity is known, `unavailable` when there is a
 * reference but no usage to compare, and `none` without a reference. It is never called a quota.
 */
final class StorageNotificationParameters
{
    /** Largest categories in the daily report. */
    public const int TOP_CATEGORIES = 3;

    /**
     * @return array<string, scalar|null>
     */
    public static function alert(StorageMeasurement $measurement, CapacityLevelChange $change): array
    {
        return [
            'direction' => $change->direction()->value,
            'from' => $change->from->value,
            'to' => $change->to->value,
            'measuredOn' => $measurement->getMeasuredOn()->format('Y-m-d'),
        ] + self::capacity($measurement);
    }

    /**
     * @param StorageMeasurement|null $previous the latest measurement before this one, if any
     *
     * @return array<string, scalar|null>
     */
    public static function report(StorageMeasurement $measurement, ?StorageMeasurement $previous): array
    {
        $usedBytes = $measurement->getUsedBytes();
        $databaseBytes = $measurement->getDatabaseBytes();
        $breakdown = new StorageBreakdown($measurement->getItems());

        $parameters = [
            'measuredOn' => $measurement->getMeasuredOn()->format('Y-m-d'),
            'level' => $measurement->getLevel()->value,
            'usage' => null === $usedBytes ? 'unavailable' : 'measured',
            'used' => null === $usedBytes ? null : ByteFormatter::format($usedBytes),
            'database' => null === $databaseBytes ? null : ByteFormatter::format($databaseBytes),
        ] + self::capacity($measurement) + self::change($usedBytes, $measurement, $previous);

        $categories = $breakdown->byCategory();
        usort($categories, static fn (StorageTotal $a, StorageTotal $b): int => $b->bytes <=> $a->bytes);
        foreach (\array_slice($categories, 0, self::TOP_CATEGORIES) as $index => $total) {
            $parameters['category'.($index + 1)] = $total->key;
            $parameters['category'.($index + 1).'Size'] = ByteFormatter::format($total->bytes);
        }

        return $parameters + self::deployment($measurement, $breakdown);
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function capacity(StorageMeasurement $measurement): array
    {
        $capacityBytes = $measurement->getCapacityBytes();
        $usage = StorageUsage::fromMeasurement($measurement);
        $source = $measurement->getCapacitySource();

        return [
            'capacitySource' => $source->value,
            'reference' => $usage?->source->value ?? (CapacitySource::None === $source ? 'none' : 'unavailable'),
            'capacity' => null === $capacityBytes ? null : ByteFormatter::format($capacityBytes),
            'comparedUsed' => $usage instanceof StorageUsage ? ByteFormatter::format($usage->usedBytes) : null,
            'usedPercent' => $usage?->usedPercent(),
            'remaining' => $usage instanceof StorageUsage ? ByteFormatter::format($usage->remainingBytes()) : null,
            'remainingPercent' => $usage?->remainingPercent(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function change(?int $usedBytes, StorageMeasurement $measurement, ?StorageMeasurement $previous): array
    {
        if (!$previous instanceof StorageMeasurement) {
            return self::noChange();
        }

        $previousBytes = $previous->getUsedBytes();
        if (null === $previousBytes || null === $usedBytes) {
            return self::noChange();
        }

        $delta = $usedBytes - $previousBytes;

        return [
            'changeDirection' => match (true) {
                $delta > 0 => 'up',
                $delta < 0 => 'down',
                default => 'none',
            },
            'change' => ByteFormatter::format(abs($delta)),
            'daysSincePrevious' => (int) $previous->getMeasuredOn()->diff($measurement->getMeasuredOn())->days,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function noChange(): array
    {
        return ['changeDirection' => 'unknown', 'change' => null, 'daysSincePrevious' => null];
    }

    /**
     * Previous releases and the shared directory exist only in deployer mode.
     *
     * @return array<string, scalar|null>
     */
    private static function deployment(StorageMeasurement $measurement, StorageBreakdown $breakdown): array
    {
        if (MeasurementMode::Deployer !== $measurement->getMode()) {
            return ['mode' => $measurement->getMode()->value, 'previousReleases' => null, 'previousReleasesSize' => null, 'shared' => null];
        }

        $releases = 0;
        $releaseBytes = 0;
        $sharedBytes = 0;
        foreach ($breakdown->byLocation() as $total) {
            if (StorageLocation::PreviousRelease->value === $total->key) {
                ++$releases;
                $releaseBytes += $total->bytes;
            } elseif (StorageLocation::Shared->value === $total->key) {
                $sharedBytes += $total->bytes;
            }
        }

        return [
            'mode' => MeasurementMode::Deployer->value,
            'previousReleases' => $releases,
            'previousReleasesSize' => ByteFormatter::format($releaseBytes),
            'shared' => ByteFormatter::format($sharedBytes),
        ];
    }
}
