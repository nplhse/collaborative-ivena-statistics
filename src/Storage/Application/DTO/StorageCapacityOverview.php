<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Application\Capacity\CapacityThresholds;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;

/**
 * Budget and filesystem side by side, never mixed (ADR 017, "Capacity reference"). The budget is compared with
 * the application usage, the filesystem with everything on the disk.
 */
final readonly class StorageCapacityOverview
{
    public function __construct(
        public CapacitySource $activeSource,
        public ?int $budgetBytes,
        public ?int $budgetUsedBytes,
        public ?float $budgetUsedPercent,
        public CapacityLevel $budgetLevel,
        public ?int $filesystemTotalBytes,
        public ?int $filesystemFreeBytes,
        public ?int $filesystemUsedBytes,
        public ?float $filesystemUsedPercent,
        public CapacityLevel $filesystemLevel,
        public CapacityThresholds $thresholds,
    ) {
    }

    public function hasBudget(): bool
    {
        return null !== $this->budgetBytes;
    }

    public function budgetStyle(): string
    {
        return LevelStyle::for($this->budgetLevel);
    }

    public function filesystemStyle(): string
    {
        return LevelStyle::for($this->filesystemLevel);
    }

    public function hasFilesystem(): bool
    {
        return null !== $this->filesystemTotalBytes && null !== $this->filesystemFreeBytes;
    }
}
