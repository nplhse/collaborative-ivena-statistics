<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\ValueRange;

/**
 * One stored variable. `$property` is the mapped field, an embedded field (`times.arrivedAt`) or a
 * path through to-one associations (`assessment.airway`).
 *
 * `$codeSetEnum` names the backed enum of a code set stored as a JSON list (several codes per record),
 * because the mapping of a JSON column has no `enumType`.
 */
final readonly class VariableDefinition
{
    /**
     * @param string|null $codeSetEnum class of a backed enum; the resolver rejects any other class
     */
    public function __construct(
        public string $key,
        public string $property,
        public ?ValueRange $range = null,
        public ?CodeLabels $codeLabels = null,
        public ?string $codeSetEnum = null,
    ) {
    }
}
