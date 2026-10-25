<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;

/**
 * One contributor (e.g. "vendor") within a category or a location, summed over the items it appears in.
 * Bytes is null when none of its items could be measured.
 */
final readonly class StorageContributorRow
{
    /**
     * @param list<MeasurementItemFlag> $flags
     */
    public function __construct(
        public string $contributor,
        public StorageCategory $category,
        public ?int $bytes,
        public ?float $sharePercent,
        public ?int $delta7Days,
        public ?int $delta30Days,
        public MeasurementItemStatus $status,
        public array $flags,
    ) {
    }

    public function labelKey(): string
    {
        return 'storage.contributor.'.$this->contributor;
    }
}
