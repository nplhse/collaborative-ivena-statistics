<?php

declare(strict_types=1);

namespace App\Import\Domain\DTO;

final readonly class ImportRateBadge
{
    public function __construct(
        public string $color,
        public string $icon,
    ) {
    }
}
