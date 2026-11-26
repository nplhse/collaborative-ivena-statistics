<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CatalogGlossaryEntryRegistry
{
    /**
     * @param iterable<CatalogGlossaryEntryProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(CatalogGlossaryEntryProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<CatalogGlossaryEntry>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->glossaryEntries() as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
