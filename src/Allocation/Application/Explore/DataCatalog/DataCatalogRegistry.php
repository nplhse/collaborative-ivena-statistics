<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects the documented data objects of all contributors and rejects duplicate object or
 * variable keys, so a key stays stable and unique across core and plugins.
 */
final readonly class DataCatalogRegistry
{
    /**
     * @param iterable<DataCatalogContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator(DataCatalogContributorInterface::TAG)]
        private iterable $contributors,
    ) {
    }

    /**
     * @return list<DataObjectDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];
        $objectKeys = [];
        $variableKeys = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->dataObjects() as $definition) {
                if (isset($objectKeys[$definition->key])) {
                    throw new \LogicException(sprintf('Duplicate data catalog object key "%s".', $definition->key));
                }

                $objectKeys[$definition->key] = true;

                foreach ($definition->groups as $group) {
                    foreach ($group->variables as $variable) {
                        if (isset($variableKeys[$variable->key])) {
                            throw new \LogicException(sprintf('Duplicate data catalog variable key "%s".', $variable->key));
                        }

                        $variableKeys[$variable->key] = true;
                    }
                }

                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}
