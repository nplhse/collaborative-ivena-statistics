<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogGlossaryUsage;
use App\Allocation\Application\DTO\DataCatalog\CatalogGroup;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The data objects the current user may see, with their variables resolved from the mapping.
 * References link to the catalog entry of their target when that object is documented and visible.
 */
final readonly class DataCatalog
{
    public function __construct(
        private DataCatalogRegistry $registry,
        private DataCatalogMetadataResolver $resolver,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<CatalogObject>
     */
    public function visibleObjects(): array
    {
        $objects = [];
        foreach ($this->registry->definitions() as $definition) {
            if (null !== $definition->grantedAttribute && !$this->authorization->isGranted($definition->grantedAttribute)) {
                continue;
            }

            $objects[] = $this->resolver->resolve($definition);
        }

        $byClass = [];
        foreach ($objects as $object) {
            $byClass[$object->entityClass] = $object;
        }

        return array_map(static fn (CatalogObject $object): CatalogObject => new CatalogObject(
            $object->key,
            $object->entityClass,
            $object->domain,
            $object->icon,
            $object->table,
            $object->contributedByPlugin,
            array_map(static fn (CatalogGroup $group): CatalogGroup => new CatalogGroup(
                $group->key,
                array_map(
                    static fn (CatalogVariable $variable): CatalogVariable => null === $variable->referenceClass
                        ? $variable
                        : $variable->withReferenceObject($byClass[$variable->referenceClass]->key ?? null, $byClass[$variable->referenceClass]->domain ?? null),
                    $group->variables,
                ),
            ), $object->groups),
        ), $objects);
    }

    /**
     * Visible variables whose values the glossary page with this route and parameters defines (#1507).
     *
     * @param array<string, string> $parameters
     *
     * @return list<CatalogGlossaryUsage>
     */
    public function glossaryUsages(string $route, array $parameters = []): array
    {
        $usages = [];
        foreach ($this->visibleObjects() as $object) {
            foreach ($object->groups as $group) {
                foreach ($group->variables as $variable) {
                    if (true === $variable->glossary?->isFor($route, $parameters)) {
                        $usages[] = new CatalogGlossaryUsage($object->key, $object->domain, $variable);
                    }
                }
            }
        }

        return $usages;
    }

    public function visibleObject(string $key): ?CatalogObject
    {
        foreach ($this->visibleObjects() as $object) {
            if ($object->key === $key) {
                return $object;
            }
        }

        return null;
    }
}
