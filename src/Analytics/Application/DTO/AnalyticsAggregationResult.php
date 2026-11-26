<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class AnalyticsAggregationResult
{
    public function __construct(
        public string $date,
        public int $rawRequestCount,
        public int $aggregateRowsWritten,
        /** True when an aggregated day without raw rows was left unchanged. */
        public bool $keptExisting = false,
        /** Requests of the day without counted-access flag; the day gets no access counts then. */
        public int $unclassifiedRequests = 0,
    ) {
    }
}
