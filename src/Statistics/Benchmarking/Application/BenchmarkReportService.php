<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Benchmarking\Application\Contract\BenchmarkAggregationProviderInterface;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkCriteria;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeader;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;

final readonly class BenchmarkReportService
{
    private const int MIN_PRIMARY_CASES = 100;

    private const int MIN_COMPARISON_CASES = 500;

    public function __construct(
        private BenchmarkAggregationProviderInterface $aggregationProvider,
        private BenchmarkMetricBuilder $metricBuilder,
        private BenchmarkHeatmapBuilder $heatmapBuilder,
        private BenchmarkInsightProvider $insightProvider,
    ) {
    }

    public function build(BenchmarkCriteria $criteria): BenchmarkReport
    {
        $aggregation = $this->aggregationProvider->aggregate(
            $criteria->primaryScope,
            $criteria->primaryPeriod,
            $criteria->comparisonScope,
            $criteria->comparisonPeriod,
        );

        $kpiMetrics = $this->metricBuilder->buildKpiMetrics($aggregation);
        $executiveSummary = $this->insightProvider->build($aggregation, $kpiMetrics);

        return new BenchmarkReport(
            new BenchmarkHeader(
                $criteria->primaryScopeLabel,
                $criteria->comparisonScopeLabel,
                $criteria->primaryPeriodLabel,
                $criteria->comparisonPeriodLabel,
                $aggregation->primary->total,
                $aggregation->comparison->total,
            ),
            $executiveSummary,
            $kpiMetrics,
            $this->metricBuilder->buildIndicationMix($aggregation),
            $this->heatmapBuilder->buildDayTimeCaseDistribution($aggregation),
            $this->heatmapBuilder->buildShiftCaseDistribution($aggregation),
            $this->metricBuilder->buildGenderDistribution($aggregation),
            $this->metricBuilder->buildAgeGroupDistribution($aggregation),
            $this->metricBuilder->buildTransportTimeDistribution($aggregation),
            $this->metricBuilder->buildTransportTypeDistribution($aggregation),
            $this->metricBuilder->buildDayTimeBucketDistribution($aggregation),
            $this->metricBuilder->buildShiftBucketDistribution($aggregation),
            $this->metricBuilder->buildUrgencyDistribution($aggregation),
            $this->metricBuilder->buildResourcesDistribution($aggregation),
            $this->metricBuilder->buildClinicalFeaturesDistribution($aggregation),
            $aggregation->primary->total < self::MIN_PRIMARY_CASES
                || $aggregation->comparison->total < self::MIN_COMPARISON_CASES,
        );
    }
}
