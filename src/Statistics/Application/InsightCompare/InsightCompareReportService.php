<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare;

use App\Statistics\Application\InsightCompare\DTO\InsightCompareCriteria;
use App\Statistics\Application\InsightCompare\DTO\InsightCompareHeader;
use App\Statistics\Application\InsightCompare\DTO\InsightCompareReport;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Benchmarking\Application\BenchmarkHeatmapBuilder;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Infrastructure\Query\InsightCompare\Dto\InsightCompareAggregationResult;
use App\Statistics\Infrastructure\Query\InsightCompare\InsightCompareMetricsQuery;
use App\Statistics\Infrastructure\Query\InsightCompare\InsightCompareSliceQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class InsightCompareReportService
{
    private const int MIN_CASES_WARNING = 10;

    private const int MIN_CASES_RATIOS = 20;

    public function __construct(
        private InsightCompareMetricsQuery $metricsQuery,
        private InsightCompareSliceQuery $sliceQuery,
        private InsightCompareBenchmarkAdapter $benchmarkAdapter,
        private BenchmarkMetricBuilder $benchmarkMetricBuilder,
        private BenchmarkHeatmapBuilder $heatmapBuilder,
        private InsightCompareInsightEngine $insightEngine,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $disabledInsightIds
     */
    public function build(
        InsightCompareCriteria $criteria,
        string $filterLabelA,
        string $filterLabelB,
        array $disabledInsightIds = [],
    ): InsightCompareReport {
        $metricsResult = $this->metricsQuery->fetch(
            $criteria->subjectA->population,
            $criteria->subjectB->population,
            $criteria->scopeA,
            $criteria->periodA,
            $criteria->scopeB,
            $criteria->periodB,
        );

        $sliceRows = $this->sliceQuery->fetch(
            $criteria->subjectA->population,
            $criteria->subjectB->population,
            $criteria->scopeA,
            $criteria->periodA,
            $criteria->scopeB,
            $criteria->periodB,
        );

        $aggregation = new InsightCompareAggregationResult(
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

        return new InsightCompareReport(
            new InsightCompareHeader(
                $criteria->subjectA->dimension,
                $criteria->subjectA->id,
                $criteria->subjectA->label,
                $this->dimensionLabel($criteria->subjectA->dimension),
                $filterLabelA,
                $criteria->subjectB->dimension,
                $criteria->subjectB->id,
                $criteria->subjectB->label,
                $this->dimensionLabel($criteria->subjectB->dimension),
                $filterLabelB,
                $totalA,
                $totalB,
            ),
            $this->benchmarkMetricBuilder->buildCompareKpiMetrics($benchmark),
            $this->benchmarkMetricBuilder->buildGenderDistribution($benchmark),
            $this->benchmarkAdapter->buildUrgencyDistribution($metricsResult->sideA, $metricsResult->sideB),
            $this->benchmarkMetricBuilder->buildResourcesDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildClinicalFeaturesDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildTransportTypeDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildAgeGroupDistribution($benchmark),
            $this->benchmarkMetricBuilder->buildTransportTimeDistribution($benchmark),
            $dayTimeHeatmap,
            $shiftHeatmap,
            $this->insightEngine->build($metricsResult->sideA, $metricsResult->sideB, $disabledInsightIds),
            $hasInsufficientData,
            $suppressRatios,
        );
    }

    private function dimensionLabel(InsightDimensionKey $dimension): string
    {
        return $this->translator->trans(
            'stats.insights.dimension.'.str_replace('-', '_', $dimension->value).'.label',
            [],
            'statistics',
        );
    }
}
