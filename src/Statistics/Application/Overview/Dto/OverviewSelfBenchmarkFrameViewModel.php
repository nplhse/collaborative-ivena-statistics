<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

use App\Statistics\Application\Insights\HospitalInsight;
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
    ) {
    }
}
