<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Multi-series month axis for analysis charts/tables (axis aligned across segments).
 *
 * @param list<string>                           $labels                                     Short labels for charts (e.g. month abbreviations)
 * @param list<string>                           $monthKeys                                  Normalized keys Y-m
 * @param list<AllocationsOverTimeSeriesSegment> $segments
 * @param list<int>|null                         $countsAllocationsMatchingAtLeastOneSegment Per axis point: allocations matching at least one charted segment (for virtual remainder when segments overlap)
 */
final readonly class AllocationsOverTimeSeries
{
    /**
     * @param list<string>                           $labels
     * @param list<string>                           $monthKeys
     * @param list<AllocationsOverTimeSeriesSegment> $segments
     * @param list<int>|null                         $countsAllocationsMatchingAtLeastOneSegment
     */
    public function __construct(
        public array $labels,
        public array $monthKeys,
        public array $segments,
        public ?array $countsAllocationsMatchingAtLeastOneSegment = null,
    ) {
    }

    /**
     * Sum of all segments per month (for summary headline stats).
     *
     * @return list<int>
     */
    public function totalsPerMonth(): array
    {
        $n = \count($this->monthKeys);
        if ([] === $this->segments) {
            /* @var list<int> */
            return array_fill(0, $n, 0);
        }

        /** @var list<int> $totals */
        $totals = array_fill(0, $n, 0);
        foreach ($this->segments as $segment) {
            foreach ($segment->values as $i => $v) {
                if ($i < $n) {
                    $totals[$i] += $v;
                }
            }
        }

        return $totals;
    }
}
