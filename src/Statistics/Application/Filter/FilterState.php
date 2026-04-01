<?php

declare(strict_types=1);

namespace App\Statistics\Application\Filter;

final readonly class FilterState
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}
