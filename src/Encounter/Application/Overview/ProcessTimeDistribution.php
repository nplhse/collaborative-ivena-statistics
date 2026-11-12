<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Valid durations of one process interval for one initial MTS category ('' = not documented), as read
 * from the database: count and continuous percentiles P10, Q1, median, Q3, P90 in minutes. P10 and
 * P90 are the box plot whiskers; minimum and maximum are never shown, as they describe single visits.
 */
final readonly class ProcessTimeDistribution
{
    public function __construct(
        public string $category,
        public int $valid,
        public ?float $p10,
        public ?float $q1,
        public ?float $median,
        public ?float $q3,
        public ?float $p90,
    ) {
    }
}
