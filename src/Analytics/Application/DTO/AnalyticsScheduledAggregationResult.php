<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class AnalyticsScheduledAggregationResult
{
    /**
     * @param list<string> $dates
     */
    public function __construct(
        public array $dates,
        public int $daysProcessed,
        public int $totalRawRequests,
        public int $totalRawEvents,
        public int $totalAggregateRows,
        public int $rawRowsDeleted,
    ) {
    }
}
