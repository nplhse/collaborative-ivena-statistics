<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkReport
{
    /**
     * @param list<BenchmarkInsight> $insights
     * @param list<BenchmarkMetric>  $kpiMetrics
     */
    public function __construct(
        public BenchmarkHeader $header,
        public array $insights,
        public array $kpiMetrics,
        public BenchmarkDistribution $indicationMix,
        public BenchmarkHeatmapData $dayTimeCaseDistribution,
        public BenchmarkHeatmapData $shiftCaseDistribution,
        public BenchmarkDistribution $gender,
        public BenchmarkDistribution $ageGroups,
        public BenchmarkDistribution $transportTimes,
        public BenchmarkDistribution $transportType,
        public BenchmarkDistribution $dayTimeBuckets,
        public BenchmarkDistribution $shiftBuckets,
        public BenchmarkDistribution $urgency,
        public BenchmarkDistribution $resourceProfile,
        public BenchmarkDistribution $clinicalFeatures,
        public bool $hasInsufficientData,
        public bool $suppressRatios,
    ) {
    }
}
