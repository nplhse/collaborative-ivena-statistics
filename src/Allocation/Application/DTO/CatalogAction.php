<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

/** @psalm-immutable */
final readonly class CatalogAction
{
    public function __construct(
        public string $label,
        public string $url,
        public string $icon = 'tabler:list',
        public bool $primary = false,
    ) {
    }
}
