<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class KpiScheduledAggregationResult
{
    /**
     * @param list<string> $dates
     */
    public function __construct(
        public array $dates,
        public int $daysProcessed,
        public int $totalRows,
        public int $daysWithData,
    ) {
    }
}
