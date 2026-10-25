<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\StorageCategory;

/**
 * One row of the category table on the Disk Usage page.
 */
final readonly class StorageCategoryRow
{
    /**
     * @param int|null                    $delta7Days   change since the measurement seven days earlier; null without one
     * @param list<MeasurementItemFlag>   $flags
     * @param list<StorageContributorRow> $contributors largest first
     */
    public function __construct(
        public StorageCategory $category,
        public int $bytes,
        public float $sharePercent,
        public ?int $delta7Days,
        public ?int $delta30Days,
        public bool $complete,
        public bool $failed,
        public array $flags,
        public array $contributors,
    ) {
    }
}
