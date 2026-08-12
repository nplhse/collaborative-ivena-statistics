<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly\Dto;

final readonly class MonthlyReportSegment
{
    public function __construct(
        public string $label,
        public int $count,
        public float $percent,
        public string $barClass,
        public ?float $percentDeltaPp = null,
    ) {
    }
}
