<?php

declare(strict_types=1);

namespace App\Content\Application\Dashboard\DTO;

final readonly class DashboardMetrics
{
    /**
     * @param list<DashboardMetric> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function value(string $key): int
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item->value;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown dashboard metric "%s".', $key));
    }
}
