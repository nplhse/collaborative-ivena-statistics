<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare;

use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareCriteria;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareHeader;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareReport;
use App\Statistics\Benchmarking\Application\BenchmarkHeatmapBuilder;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareAggregationResult;
use App\Statistics\Infrastructure\Query\IndicationCompare\IndicationCompareMetricsQuery;
use App\Statistics\Infrastructure\Query\IndicationCompare\IndicationCompareSliceQuery;

final readonly class IndicationCompareReportService
{
    private const int MIN_CASES_WARNING = 10;

    private const int MIN_CASES_RATIOS = 20;

    public function __construct(
        private IndicationCompareMetricsQuery $metricsQuery,
        private IndicationCompareSliceQuery $sliceQuery,
        private IndicationCompareBenchmarkAdapter $benchmarkAdapter,
        private BenchmarkMetricBuilder $benchmarkMetricBuilder,
        private BenchmarkHeatmapBuilder $heatmapBuilder,
        private IndicationCompareInsightEngine $insightEngine,
    ) {
    }

    public function build(IndicationCompareCriteria $criteria): IndicationCompareReport
    {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;

        $metricsResult = $this->metricsQuery->fetch(
            $criteria->subjectA->indicationIds,
            $criteria->subjectB->indicationIds,
            $from,
            $toExclusive,
            $scope,
        );

        $sliceRows = $this->sliceQuery->fetch(
            $criteria->subjectA->indicationIds,
            $criteria->subjectB->indicationIds,
            $from,
            $toExclusive,
            $scope,
        );

        $aggregation = new IndicationCompareAggregationResult(
            $metricsResult->sideA,
            $metricsResult->sideB,
            $sliceRows,
        );

        $benchmark = $this->benchmarkAdapter->toBenchmarkAggregation($aggregation);

        $totalA = $metricsResult->sideA->total;
        $totalB = $metricsResult->sideB->total;
        $hasInsufficientData = $totalA < self::MIN_CASES_WARNING || $totalB < self::MIN_CASES_WARNING;
        $suppressRatios = $totalA < self::MIN_CASES_RATIOS || $totalB < self::MIN_CASES_RATIOS;

        $dayTimeHeatmap = $this->heatmapBuilder->buildDayTimeCaseDistribution($benchmark);
        $shiftHeatmap = $this->heatmapBuilder->buildShiftCaseDistribution($benchmark);

        return new IndicationCompareReport(
            new IndicationCompareHeader(
                $criteria->subjectA->type,
                $criteria->subjectA->id,
                $criteria->subjectA->label,
                $criteria->subjectB->type,
                $criteria->subjectB->id,
                $criteria->subjectB->label,
                $totalA,
                $totalB,
            ),
            $this->benchmarkMetricBuilder->buildIndicationCompareKpiMetrics($benchmark),
            $this->benchmarkMetricBuilder->buildGenderDistribution($benchmark),
            $this->benchmarkAdapter->buildUrgencyDistribution($metricsResult->sideA, $metricsResult->sideB),
            $this->benchmarkMetricBuilder->buildResourcesDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildClinicalFeaturesDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildTransportTypeDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildAgeGroupDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildTransportTimeDistribution($benchmark),
            $dayTimeHeatmap,
            $shiftHeatmap,
            $this->insightEngine->build($metricsResult->sideA, $metricsResult->sideB),
            $hasInsufficientData,
            $suppressRatios,
        );
    }
}
