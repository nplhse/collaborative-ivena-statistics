<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point of the Explore data catalog (#1415).
 *
 * Every context, the core included, documents the stored fields of its data objects through this
 * interface; Allocation does not know the contributors. Only listed fields appear (opt-in). Type,
 * nullability, table and column are read from the Doctrine mapping, labels and descriptions come
 * from translation keys derived from the variable key.
 */
#[AutoconfigureTag(self::TAG)]
interface DataCatalogContributorInterface
{
    public const string TAG = 'allocation.data_catalog_object';

    /**
     * @return list<DataObjectDefinition>
     */
    public function dataObjects(): array;
}
