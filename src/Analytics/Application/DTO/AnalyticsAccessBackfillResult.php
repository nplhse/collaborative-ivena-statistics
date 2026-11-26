<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class AnalyticsAccessBackfillResult
{
    public function __construct(
        public int $rowsClassified,
        public int $rowsCounted,
    ) {
    }
}
