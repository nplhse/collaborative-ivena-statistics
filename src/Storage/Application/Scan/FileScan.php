<?php

declare(strict_types=1);

namespace App\Storage\Application\Scan;

use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Result of one walk over the measurement root.
 */
final readonly class FileScan
{
    /**
     * @param list<StorageMeasurementItem> $items
     * @param list<string>                 $failures paths that could not be read, with the reason
     * @param bool                         $complete false when a whole location could not be read
     */
    public function __construct(
        public array $items,
        public int $entries,
        public array $failures,
        public bool $complete,
    ) {
    }

    /**
     * Sum of all measured file items; null when the scan is incomplete.
     */
    public function totalBytes(): ?int
    {
        if (!$this->complete) {
            return null;
        }

        $total = 0;
        foreach ($this->items as $item) {
            if (MeasurementItemStatus::Measured === $item->status) {
                $total += (int) $item->bytes;
            }
        }

        return $total;
    }
}
