<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Overview\OverviewComparisonPeriod;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;

final readonly class OverviewSelfBenchmarkFrameViewModel
{
    /**
     * @param list<BenchmarkMetric> $kpiMetrics
     * @param list<HospitalInsight> $hospitalInsights
     */
    public function __construct(
        public array $kpiMetrics,
        public array $hospitalInsights,
        public bool $benchmarkSuppressRatios,
        public string $benchmarkingUrl,
        public ?OverviewComparisonPeriod $comparison = null,
    ) {
    }

    public function hasComparison(): bool
    {
        return $this->comparison instanceof OverviewComparisonPeriod;
    }

    public function comparisonFirstDay(): ?\DateTimeImmutable
    {
        return $this->comparison?->comparison->from;
    }

    /**
     * Last day covered by the comparison period (its end is exclusive).
     */
    public function comparisonLastDay(): ?\DateTimeImmutable
    {
        return $this->comparison?->comparison->toExclusive?->modify('-1 second');
    }
}
