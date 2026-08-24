<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

final readonly class PlatformAllocationCounts
{
    public function __construct(
        public int $total,
        public int $last30Days,
    ) {
    }
}
