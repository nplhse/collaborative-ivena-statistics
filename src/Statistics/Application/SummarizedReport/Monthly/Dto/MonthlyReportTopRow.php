<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly\Dto;

final readonly class MonthlyReportTopRow
{
    public function __construct(
        public int $rank,
        public string $label,
        public int $count,
        public string $shareDisplay,
    ) {
    }
}
