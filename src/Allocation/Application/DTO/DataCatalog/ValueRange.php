<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

/**
 * Domain range of a number, not the range observed in the data. `$unitKey` is translated in the
 * object's domain.
 */
final readonly class ValueRange
{
    public function __construct(
        public int|float|null $min,
        public int|float|null $max,
        public ?string $unitKey = null,
    ) {
    }
}
