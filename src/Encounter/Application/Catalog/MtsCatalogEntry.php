<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

/**
 * One active diagram or indicator of the MTS catalog: stable code, German name and the English name, if one is set.
 */
final readonly class MtsCatalogEntry
{
    public function __construct(
        public string $code,
        public string $germanName,
        public ?string $englishName = null,
    ) {
    }
}
