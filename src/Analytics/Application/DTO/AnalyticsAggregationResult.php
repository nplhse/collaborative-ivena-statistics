<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class AnalyticsAggregationResult
{
    public function __construct(
        public string $date,
        public int $rawRequestCount,
        public int $rawEventCount,
        public int $aggregateRowsWritten,
    ) {
    }
}
