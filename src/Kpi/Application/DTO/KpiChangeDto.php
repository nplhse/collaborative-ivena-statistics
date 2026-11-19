<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

/**
 * Change of a KPI against the previous period of the same length.
 * A null delta means there is nothing to compare with (no data in one of the two periods).
 */
final readonly class KpiChangeDto
{
    public const string UNIT_COUNT = 'count';
    public const string UNIT_PERCENT = 'percent';
    public const string UNIT_POINTS = 'points';

    public function __construct(
        public ?float $delta,
        public string $unit,
        public bool $lowerIsBetter = false,
    ) {
    }

    /**
     * Bootstrap colour for the change: green when it improves, red when it gets worse.
     */
    public function tone(): string
    {
        if (null === $this->delta || 0.0 === $this->delta) {
            return 'secondary';
        }

        return ($this->delta < 0) === $this->lowerIsBetter ? 'success' : 'danger';
    }
}
