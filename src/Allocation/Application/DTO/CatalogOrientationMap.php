<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

/** @psalm-immutable */
final readonly class CatalogOrientationMap
{
    public function __construct(
        public bool $enabled,
        public ?string $highlightKey = null,
        public bool $showAllAreas = false,
    ) {
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
