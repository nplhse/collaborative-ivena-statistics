<?php

declare(strict_types=1);

namespace App\Statistics\Application\Filter;

final readonly class FilterDefinition
{
    /**
     * @param list<int>|null $choices
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $field,
        public mixed $defaultValue = null,
        public bool $multiple = false,
        public ?array $choices = null,
    ) {
    }
}
