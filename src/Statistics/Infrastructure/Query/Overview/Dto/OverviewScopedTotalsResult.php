<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview\Dto;

final readonly class OverviewScopedTotalsResult
{
    public function __construct(
        public int $platformTotal,
        public int $scopedTotal,
    ) {
    }
}
