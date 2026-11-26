<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

/**
 * A documented data object: one entity and the groups of its stored variables.
 *
 * Translation keys in `$translationDomain`: `data_catalog.object.<key>.title|description`,
 * `data_catalog.group.<group>` and `data_catalog.variable.<variable key>.label|description`.
 */
final readonly class DataObjectDefinition
{
    /**
     * @param class-string                  $entityClass
     * @param list<VariableGroupDefinition> $groups
     * @param string|null                   $grantedAttribute shown only to users granted this attribute
     */
    public function __construct(
        public string $key,
        public string $entityClass,
        public string $translationDomain,
        public string $icon,
        public array $groups,
        public bool $contributedByPlugin = false,
        public ?string $grantedAttribute = null,
    ) {
    }
}
