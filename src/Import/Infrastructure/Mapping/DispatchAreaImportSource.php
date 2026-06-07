<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

final readonly class DispatchAreaImportSource
{
    public function __construct(
        public ?string $value,
        public string $column,
    ) {
    }
}
