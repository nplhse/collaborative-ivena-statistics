<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

/**
 * One diagram or indicator of the MTS catalog: stable code and German name.
 */
final readonly class MtsCatalogEntry
{
    public function __construct(
        public string $code,
        public string $germanName,
    ) {
    }
}
