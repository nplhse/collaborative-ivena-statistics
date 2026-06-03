<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class GenericAnalysisReducedChartData
{
    /**
     * @param list<string>                                    $labels
     * @param list<int>|null                                  $counts
     * @param list<array{name: string, data: list<int>}>|null $series
     */
    public function __construct(
        public array $labels,
        public ?array $counts = null,
        public ?array $series = null,
        public bool $limitedPrimaryBuckets = false,
        public bool $limitedSeries = false,
    ) {
    }

    public function wasLimited(): bool
    {
        return $this->limitedPrimaryBuckets || $this->limitedSeries;
    }
}
