<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

final readonly class CatalogCode
{
    public function __construct(
        public string $code,
        public ?string $labelKey,
        public ?string $domain,
    ) {
    }
}
