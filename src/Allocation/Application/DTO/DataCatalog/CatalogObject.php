<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

final readonly class CatalogObject
{
    /**
     * @param class-string       $entityClass
     * @param list<CatalogGroup> $groups
     */
    public function __construct(
        public string $key,
        public string $entityClass,
        public string $domain,
        public string $icon,
        public string $table,
        public bool $contributedByPlugin,
        public array $groups,
    ) {
    }

    public function titleKey(): string
    {
        return 'data_catalog.object.'.$this->key.'.title';
    }

    public function descriptionKey(): string
    {
        return 'data_catalog.object.'.$this->key.'.description';
    }

    public function variableCount(): int
    {
        return array_sum(array_map(static fn (CatalogGroup $group): int => \count($group->variables), $this->groups));
    }

    public function variable(string $key): ?CatalogVariable
    {
        foreach ($this->groups as $group) {
            foreach ($group->variables as $variable) {
                if ($variable->key === $key) {
                    return $variable;
                }
            }
        }

        return null;
    }
}
