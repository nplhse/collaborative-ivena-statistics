<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewTopTableRow
{
    public function __construct(
        public int $rank,
        public string $label,
        public int $count,
        public string $shareDisplay,
    ) {
    }
}
