<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Benchmarking\Application\Contract\BenchmarkAggregationProviderInterface;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkCriteria;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeader;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;

final readonly class BenchmarkReportService
{
    private const int MIN_PRIMARY_CASES = 100;

    private const int MIN_COMPARISON_CASES = 500;

    private const int MIN_CASES_RATIOS = 20;

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

        return $this->mapAggregationToReport($criteria, $aggregation);
    }

    public function buildForOverview(BenchmarkCriteria $criteria): BenchmarkReport
    {
        $aggregation = $this->aggregationProvider->aggregateForOverview(
            $criteria->primaryScope,
            $criteria->primaryPeriod,
            $criteria->comparisonScope,
            $criteria->comparisonPeriod,
        );

        return $this->mapAggregationToReport($criteria, $aggregation, overview: true);
    }

    private function mapAggregationToReport(
        BenchmarkCriteria $criteria,
        BenchmarkAggregationResult $aggregation,
        bool $overview = false,
    ): BenchmarkReport {
        $kpiMetrics = $this->metricBuilder->buildIndicationCompareKpiMetrics($aggregation);
        $insights = $overview ? [] : $this->insightProvider->build($aggregation, $kpiMetrics);
        $suppressRatios = $aggregation->primary->total < self::MIN_CASES_RATIOS
            || $aggregation->comparison->total < self::MIN_CASES_RATIOS;

        return new BenchmarkReport(
            new BenchmarkHeader(
                $criteria->primaryScopeLabel,
                $criteria->comparisonScopeLabel,
                $criteria->primaryPeriodLabel,
                $criteria->comparisonPeriodLabel,
                $aggregation->primary->total,
                $aggregation->comparison->total,
            ),
            $insights,
            $kpiMetrics,
            $this->metricBuilder->buildIndicationMix($aggregation),
            $overview ? BenchmarkHeatmapData::empty() : $this->heatmapBuilder->buildDayTimeCaseDistribution($aggregation),
            $overview ? BenchmarkHeatmapData::empty() : $this->heatmapBuilder->buildShiftCaseDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::Gender) : $this->metricBuilder->buildGenderDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::AgeGroups) : $this->metricBuilder->buildAgeGroupDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::TransportTimes) : $this->metricBuilder->buildTransportTimeDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::TransportType) : $this->metricBuilder->buildTransportTypeDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::DayTimeBuckets) : $this->metricBuilder->buildDayTimeBucketDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::ShiftBuckets) : $this->metricBuilder->buildShiftBucketDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::Urgency) : $this->metricBuilder->buildUrgencyDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::ResourceProfile) : $this->metricBuilder->buildResourcesDistribution($aggregation),
            $overview ? $this->emptyDistribution(BenchmarkMetricKey::ClinicalFeatures) : $this->metricBuilder->buildClinicalFeaturesDistribution($aggregation),
            $aggregation->primary->total < self::MIN_PRIMARY_CASES
                || $aggregation->comparison->total < self::MIN_COMPARISON_CASES,
            $suppressRatios,
        );
    }

    private function emptyDistribution(BenchmarkMetricKey $key): BenchmarkDistribution
    {
        return new BenchmarkDistribution($key, []);
    }
}
