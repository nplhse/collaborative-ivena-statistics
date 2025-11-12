<?php

declare(strict_types=1);

namespace App\Model;

final readonly class TimeGridCell
{
    /**
     * @param array<string, mixed>|null $stats
     */
    public function __construct(
        public int|float|null $value,

        public ?float $deltaAbs = null,

        public ?float $deltaPct = null,

        public int|float|null $compare = null,

        /** @var array<string, mixed>|null */
        public ?array $stats = null,
    ) {
    }
}
