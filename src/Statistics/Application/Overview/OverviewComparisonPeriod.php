<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;

/**
 * The overview self-benchmark compares a period with the period of the same length directly before it (#1071).
 *
 * A running period is compared up to now: `primary` ends now, and `comparison` covers the same span from the start
 * of the previous period (for example 1–13 November against 1–13 October).
 */
final readonly class OverviewComparisonPeriod
{
    public function __construct(
        public StatisticsPeriodBounds $primary,
        public StatisticsPeriodBounds $comparison,
        public OverviewComparisonKind $kind,
    ) {
    }

    /**
     * Translation key (domain `statistics`) naming the comparison period, e.g. "the previous month".
     */
    public function labelKey(): string
    {
        return 'stats.overview.comparison.'.$this->kind->value;
    }
}
