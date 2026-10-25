<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Totals of a measurement by category and by location (ADR 017, "Two views").
 */
final readonly class StorageBreakdown
{
    /**
     * @param list<StorageMeasurementItem> $items
     */
    public function __construct(
        private array $items,
    ) {
    }

    /**
     * One total per category in display order; a category with failed or incomplete items is not complete.
     *
     * @return list<StorageTotal>
     */
    public function byCategory(): array
    {
        $totals = [];
        foreach (StorageCategory::cases() as $category) {
            $totals[] = $this->total(
                $category->value,
                null,
                array_values(array_filter($this->items, static fn (StorageMeasurementItem $item): bool => $item->category === $category)),
            );
        }

        return $totals;
    }

    /**
     * One total per location and detail (e.g. per release), in measurement order.
     *
     * @return list<StorageTotal>
     */
    public function byLocation(): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groups[$item->location->value."\0".($item->locationDetail ?? '')][] = $item;
        }

        return array_values(array_map(
            fn (array $items): StorageTotal => $this->total($items[0]->location->value, $items[0]->locationDetail, $items),
            $groups,
        ));
    }

    /**
     * @param list<StorageMeasurementItem> $items
     */
    private function total(string $key, ?string $detail, array $items): StorageTotal
    {
        $bytes = 0;
        $complete = true;
        $flags = [];

        foreach ($items as $item) {
            if (MeasurementItemStatus::Measured === $item->status) {
                $bytes += (int) $item->bytes;
            } else {
                $complete = false;
            }

            foreach ($item->flags as $flag) {
                $flags[$flag->value] = $flag;
                $complete = $complete && MeasurementItemFlag::Incomplete !== $flag;
            }
        }

        return new StorageTotal($key, $detail, $bytes, $complete, array_values($flags));
    }
}
