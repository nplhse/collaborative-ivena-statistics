<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

/**
 * Entry of a catalog section: identified by its public id, named for people.
 */
interface CatalogEntry
{
    public string $id { get; }

    public string $name { get; }
}
