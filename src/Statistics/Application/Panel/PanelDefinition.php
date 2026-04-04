<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel;

use App\Statistics\Application\Panel\Distribution\DimensionKind;

final readonly class PanelDefinition
{
    /**
     * @param list<string>         $filters
     * @param array<string, mixed> $filterDefaults
     * @param array<string,scalar> $options
     * @param array<string, bool>  $controls
     */
    public function __construct(
        public string $key,
        public string $type,
        public DimensionKind $dimensionKind,
        public string $dimensionField,
        public string $dimensionLabel,
        public ?string $groupByField,
        public ?string $groupByLabel,
        public array $filters,
        public array $options,
        public array $controls,
        public array $filterDefaults = [],
        public ?string $averageMetric = null,
    ) {
    }

    public function hasFilter(string $key): bool
    {
        return \in_array($key, $this->filters, true);
    }
}
