<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel;

final readonly class PanelDefinition
{
    /**
     * @param list<string>         $filters
     * @param array<string,scalar> $options
     * @param array<string, bool>  $controls
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $dimensionField,
        public string $dimensionLabel,
        public ?string $groupByField,
        public ?string $groupByLabel,
        public array $filters,
        public array $options,
        public array $controls,
    ) {
    }

    public function hasFilter(string $key): bool
    {
        return \in_array($key, $this->filters, true);
    }
}
