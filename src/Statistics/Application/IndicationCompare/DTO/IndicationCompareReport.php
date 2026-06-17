<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;

final readonly class IndicationCompareReport
{
    /**
     * @param list<BenchmarkMetric>          $kpiMetrics
     * @param list<IndicationCompareInsight> $insights
     */
    public function __construct(
        public IndicationCompareHeader $header,
        public array $kpiMetrics,
        public BenchmarkDistribution $genderDistribution,
        public BenchmarkDistribution $urgencyDistribution,
        public BenchmarkDistribution $resourcesDistribution,
        public BenchmarkDistribution $clinicalFeaturesDistribution,
        public BenchmarkDistribution $transportTypeDistribution,
        public BenchmarkDistribution $ageGroupDistribution,
        public BenchmarkDistribution $transportTimeDistribution,
        public BenchmarkHeatmapData $dayTimeHeatmap,
        public BenchmarkHeatmapData $shiftHeatmap,
        public array $insights,
        public bool $hasInsufficientData,
        public bool $suppressRatios,
    ) {
    }
}
