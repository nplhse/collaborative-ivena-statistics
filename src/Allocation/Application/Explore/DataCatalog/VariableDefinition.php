<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\ValueRange;

/**
 * One stored variable. `$property` is the mapped field, an embedded field (`times.arrivedAt`) or a
 * path through to-one associations (`assessment.airway`).
 */
final readonly class VariableDefinition
{
    public function __construct(
        public string $key,
        public string $property,
        public ?ValueRange $range = null,
        public ?CodeLabels $codeLabels = null,
    ) {
    }
}
