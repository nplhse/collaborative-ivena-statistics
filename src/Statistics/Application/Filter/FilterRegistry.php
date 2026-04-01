<?php

declare(strict_types=1);

namespace App\Statistics\Application\Filter;

final class FilterRegistry
{
    /**
     * @var array<string, FilterDefinition>
     */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            'date_range' => new FilterDefinition(
                key: 'date_range',
                type: 'date_range',
                field: 'created_at',
                defaultValue: 'all_cases',
            ),
        ];
    }

    public function get(string $key): FilterDefinition
    {
        if (!isset($this->definitions[$key])) {
            throw new \InvalidArgumentException('Unknown filter: '.$key);
        }

        return $this->definitions[$key];
    }
}
