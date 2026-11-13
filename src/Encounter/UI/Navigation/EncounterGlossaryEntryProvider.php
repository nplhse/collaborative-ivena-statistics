<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Allocation\Application\Explore\Catalog\CatalogGlossaryEntry;
use App\Allocation\Application\Explore\Catalog\CatalogGlossaryEntryProviderInterface;

/**
 * Adds the MTS glossary (#1056) to the Explore glossary hub through the Allocation extension point,
 * so Allocation does not depend on Encounter. Definitions are for every participant, so the entry is
 * always shown.
 *
 * @psalm-suppress UnusedClass Tagged via CatalogGlossaryEntryProviderInterface.
 */
final readonly class EncounterGlossaryEntryProvider implements CatalogGlossaryEntryProviderInterface
{
    #[\Override]
    public function glossaryEntries(): array
    {
        return [
            new CatalogGlossaryEntry(
                key: 'mts',
                titleKey: 'encounter.mts.glossary.title',
                introKey: 'encounter.mts.glossary.intro',
                translationDomain: 'encounter',
                icon: 'tabler:stethoscope',
                route: 'app_explore_glossary_mts',
            ),
        ];
    }
}
