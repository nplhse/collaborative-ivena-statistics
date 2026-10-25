<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

use App\Storage\Domain\Enum\CapacitySource;

/**
 * What the usage is compared with, and which usage (ADR 017, "Capacity reference"): the measured application
 * usage for a budget, the filesystem-wide usage for the filesystem.
 */
final readonly class CapacityReference
{
    public function __construct(
        public CapacitySource $source,
        public int $capacityBytes,
        public ?int $comparedUsedBytes,
    ) {
        if (CapacitySource::None === $source || $capacityBytes <= 0) {
            throw new \InvalidArgumentException('A capacity reference needs a source and a positive capacity.');
        }
    }
}
