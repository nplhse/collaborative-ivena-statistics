<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Raw figures of one process interval over the visits of a period, as read from the database.
 *
 * `valid + missing + negative + implausible` equals the visits of the period. Quartiles are
 * continuous percentiles (PostgreSQL `percentile_cont`, linear interpolation) of the valid durations
 * in minutes; null without valid durations.
 */
final readonly class ProcessTimeStatistics
{
    public function __construct(
        public ProcessInterval $interval,
        public int $valid,
        public int $missing,
        public int $negative,
        public int $implausible,
        public ?float $q1,
        public ?float $median,
        public ?float $q3,
    ) {
    }
}
