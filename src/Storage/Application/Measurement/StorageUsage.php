<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacitySource;

/**
 * The usage a stored measurement was compared with, and how much of its capacity reference is left
 * (ADR 017, "Capacity reference"): the measured usage for a budget, total − free for the filesystem.
 */
final readonly class StorageUsage
{
    public function __construct(
        public CapacitySource $source,
        public int $usedBytes,
        public int $capacityBytes,
    ) {
        if (CapacitySource::None === $source || $capacityBytes <= 0 || $usedBytes < 0) {
            throw new \InvalidArgumentException('A storage usage needs a capacity source, a positive capacity and a usage of at least 0.');
        }
    }

    /**
     * Null without a capacity reference or without the usage it is compared with.
     */
    public static function fromMeasurement(StorageMeasurement $measurement): ?self
    {
        $capacity = $measurement->getCapacityBytes();
        $source = $measurement->getCapacitySource();
        $used = match ($source) {
            CapacitySource::Budget => $measurement->getUsedBytes(),
            CapacitySource::Filesystem => self::filesystemUsed($measurement),
            CapacitySource::None => null,
        };

        return null === $capacity || null === $used ? null : new self($source, $used, $capacity);
    }

    /**
     * Share of the capacity in use, in percent with one decimal; above 100 when exceeded.
     */
    public function usedPercent(): float
    {
        return round($this->ratio() * 100.0, 1);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->capacityBytes - $this->usedBytes);
    }

    public function remainingPercent(): float
    {
        return max(0.0, round(100.0 - $this->ratio() * 100.0, 1));
    }

    private function ratio(): float
    {
        return (float) $this->usedBytes / (float) $this->capacityBytes;
    }

    private static function filesystemUsed(StorageMeasurement $measurement): ?int
    {
        $total = $measurement->getFilesystemTotalBytes();
        $free = $measurement->getFilesystemFreeBytes();

        return null === $total || null === $free ? null : max(0, $total - $free);
    }
}
