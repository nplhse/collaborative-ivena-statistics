<?php

declare(strict_types=1);

namespace App\Storage\Application\View;

use App\Storage\Application\DTO\StorageBarSegment;
use App\Storage\Application\DTO\StorageSummary;
use App\Storage\Application\Measurement\StorageBreakdown;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\StorageCategory;
use Psr\Clock\ClockInterface;

/**
 * Turns a persisted measurement into the compact summary (ADR 017, "UI"): usage, reference, remaining capacity,
 * level, age and the segmented bar.
 */
final readonly class StorageSummaryBuilder
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private StorageSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function build(StorageMeasurement $measurement): StorageSummary
    {
        $capacity = $measurement->getCapacityBytes();
        $used = $measurement->getUsedBytes();
        $compared = $this->comparedUsedBytes($measurement);
        $remaining = null !== $capacity && null !== $compared ? $capacity - $compared : null;
        $percent = null !== $capacity && null !== $compared ? round((float) $compared / (float) $capacity * 100.0, 1) : null;
        $maxAge = $this->settings->measurementMaxAgeHours;
        $age = $this->clock->now()->getTimestamp() - $measurement->getMeasuredAt()->getTimestamp();

        return new StorageSummary(
            measuredAt: $measurement->getMeasuredAt(),
            stale: $age > $maxAge * 3600,
            maxAgeHours: $maxAge,
            mode: $measurement->getMode(),
            usedBytes: $used,
            capacitySource: $measurement->getCapacitySource(),
            capacityBytes: $capacity,
            comparedUsedBytes: $compared,
            remainingBytes: $remaining,
            usedPercent: $percent,
            level: $measurement->getLevel(),
            segments: $this->segments($measurement, $compared),
            durationMs: $measurement->getDurationMs(),
        );
    }

    private function comparedUsedBytes(StorageMeasurement $measurement): ?int
    {
        if (CapacitySource::Filesystem !== $measurement->getCapacitySource()) {
            return $measurement->getUsedBytes();
        }

        $total = $measurement->getFilesystemTotalBytes();
        $free = $measurement->getFilesystemFreeBytes();

        return null !== $total && null !== $free ? max(0, $total - $free) : null;
    }

    /**
     * @return list<StorageBarSegment>
     */
    private function segments(StorageMeasurement $measurement, ?int $compared): array
    {
        $totals = new StorageBreakdown($measurement->getItems())->byCategory();
        $applicationBytes = array_sum(array_map(static fn (\App\Storage\Application\Measurement\StorageTotal $total): int => $total->bytes, $totals));
        $outsideBytes = CapacitySource::Filesystem === $measurement->getCapacitySource() && null !== $compared
            ? max(0, $compared - $applicationBytes)
            : 0;
        $scale = max($measurement->getCapacityBytes() ?? 0, $applicationBytes + $outsideBytes);

        $segments = [];
        foreach ($totals as $total) {
            $category = StorageCategory::from($total->key);
            $segments[] = new StorageBarSegment(
                key: $category->value,
                labelKey: $category->labelKey(),
                color: $category->color(),
                icon: $category->icon(),
                bytes: $total->bytes,
                widthPercent: $this->width($total->bytes, $scale),
            );
        }

        if ($outsideBytes > 0) {
            $segments[] = new StorageBarSegment(
                key: StorageBarSegment::OUTSIDE_APPLICATION,
                labelKey: 'storage.segment.outside_application',
                color: '#ced4da',
                icon: null,
                bytes: $outsideBytes,
                widthPercent: $this->width($outsideBytes, $scale),
            );
        }

        return $segments;
    }

    private function width(int $bytes, int $scale): float
    {
        return $scale > 0 ? round((float) $bytes / (float) $scale * 100.0, 2) : 0.0;
    }
}
