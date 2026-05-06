<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * One series in "allocations over time" (e.g. total, gender, urgency).
 *
 * @param list<int> $values Per month, length matches the month axis ($monthKeys of the series)
 */
final readonly class AllocationsOverTimeSeriesSegment
{
    /**
     * @param list<int> $values
     */
    public function __construct(
        public string $segmentKey,
        public string $labelTranslationKey,
        public array $values,
    ) {
    }
}
