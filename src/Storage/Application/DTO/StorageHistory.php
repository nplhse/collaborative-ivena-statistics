<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\StorageCategory;

/**
 * Daily totals per category for the history chart, oldest first. The page filters the range on the client.
 */
final readonly class StorageHistory
{
    /**
     * @param list<string>             $days     Y-m-d of each measurement
     * @param array<string, list<int>> $series   bytes per category value, one value per day
     * @param list<int|null>           $capacity capacity reference per day
     */
    public function __construct(
        public array $days,
        public array $series,
        public array $capacity,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->days;
    }

    /**
     * @return array{days: list<string>, series: list<array{key: string, color: string, data: list<int>}>, capacity: list<int|null>}
     */
    public function toChartValue(): array
    {
        $series = [];
        foreach (StorageCategory::cases() as $category) {
            $series[] = [
                'key' => $category->value,
                'color' => $category->color(),
                'data' => $this->series[$category->value] ?? [],
            ];
        }

        return [
            'days' => $this->days,
            'series' => $series,
            'capacity' => $this->capacity,
        ];
    }
}
