<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

final readonly class VariableGroupDefinition
{
    /**
     * @param list<VariableDefinition> $variables
     */
    public function __construct(
        public string $key,
        public array $variables,
    ) {
    }
}
