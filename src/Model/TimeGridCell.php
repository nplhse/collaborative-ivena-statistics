<?php

declare(strict_types=1);

namespace App\Model;

final readonly class TimeGridCell
{
    public function __construct(
        public int|float|null $value,
        public ?float $deltaAbs = null,
        public ?float $deltaPct = null,
        public int|float|null $compare = null,
        public ?array $stats = null,
    ) {
    }
}
