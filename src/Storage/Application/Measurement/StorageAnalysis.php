<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Domain\Entity\StorageMeasurement;

/**
 * A fresh measurement with what was found on the way. Not persisted by itself.
 */
final readonly class StorageAnalysis
{
    /**
     * @param list<string> $failures
     */
    public function __construct(
        public StorageMeasurement $measurement,
        public StorageLayout $layout,
        public int $scannedEntries,
        public array $failures,
    ) {
    }

    /**
     * Whether files and database could both be measured, so a total exists.
     */
    public function isComplete(): bool
    {
        return null !== $this->measurement->getUsedBytes();
    }
}
