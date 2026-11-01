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
        /** True when an aggregated day without raw rows was left unchanged. */
        public bool $keptExisting = false,
    ) {
    }
}
