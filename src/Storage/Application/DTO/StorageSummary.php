<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;

/**
 * The latest persisted measurement as shown on the dashboard card and at the top of the Disk Usage page.
 * Never computed from a live scan.
 */
final readonly class StorageSummary
{
    /**
     * @param int|null                $comparedUsedBytes usage compared with the reference: the application usage for a
     *                                                   budget, the filesystem-wide usage for the filesystem
     * @param int|null                $remainingBytes    capacity minus compared usage; negative when exceeded
     * @param list<StorageBarSegment> $segments          categories in display order, then usage outside the application
     */
    public function __construct(
        public \DateTimeImmutable $measuredAt,
        public bool $stale,
        public int $maxAgeHours,
        public MeasurementMode $mode,
        public ?int $usedBytes,
        public CapacitySource $capacitySource,
        public ?int $capacityBytes,
        public ?int $comparedUsedBytes,
        public ?int $remainingBytes,
        public ?float $usedPercent,
        public CapacityLevel $level,
        public array $segments,
        public int $durationMs,
    ) {
    }

    public function hasCapacity(): bool
    {
        return null !== $this->capacityBytes;
    }

    public function isExceeded(): bool
    {
        return null !== $this->remainingBytes && $this->remainingBytes < 0;
    }

    /**
     * Bootstrap contextual style for the level badge.
     */
    public function levelStyle(): string
    {
        return LevelStyle::for($this->level);
    }

    /**
     * Width of the used part of the bar, for screen readers and the progress role.
     */
    public function barUsedPercent(): float
    {
        return min(100.0, round(array_sum(array_map(static fn (StorageBarSegment $segment): float => $segment->widthPercent, $this->segments)), 1));
    }
}
