<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisChartRecommendation;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final readonly class AnalysisConfigurationValidator
{
    private const int PIE_MAX_BUCKETS = 12;
    private const int BY_METRIC_MAX_SERIES = 5;

    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private GenericAnalysisChartRecommendationService $recommendationService,
        private AnalysisQueryModifierRegistry $modifierRegistry,
    ) {
    }

    public function validateQuery(AnalysisQuery $query): void
    {
        $this->modifierRegistry->validate($query);

        if (AnalysisSeriesMode::ByMetric === $query->seriesMode && null !== $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('Series dimension cannot be used with metric comparison mode.');
        }

        if (AnalysisSeriesMode::ByDimension === $query->seriesMode && AnalysisDisplayMode::PivotTable === $query->displayMode && null === $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('Pivot table requires both row and column dimensions.');
        }

        $chartType = $query->chartType;
        if (!$chartType instanceof GenericAnalysisChartType) {
            return;
        }

        if (GenericAnalysisChartType::Pie === $chartType) {
            $this->validatePieQuery($query);
        }

        if (GenericAnalysisChartType::Heatmap === $chartType) {
            $this->validateHeatmapQuery($query);
        }

        if (AnalysisSeriesMode::ByMetric === $query->seriesMode) {
            $this->validateByMetricQuery($query, $chartType);
        }
    }

    public function resolveAllowedChartTypes(
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
    ): GenericAnalysisChartRecommendation {
        $recommendation = $this->recommendationService->recommend($query, $result);

        if (AnalysisDisplayMode::PivotTable === $query->displayMode) {
            return new GenericAnalysisChartRecommendation(
                hasChart: false,
                defaultChartType: GenericAnalysisChartType::Table,
                allowedChartTypes: [GenericAnalysisChartType::Table],
                warnings: $recommendation->warnings,
                reason: 'pivot_table',
            );
        }

        if (AnalysisSeriesMode::ByMetric === $query->seriesMode) {
            return $this->adjustForByMetric($query, $recommendation);
        }

        return $this->adjustForExplicitChartType($query, $recommendation);
    }

    private function validatePieQuery(AnalysisQuery $query): void
    {
        if (null !== $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('Pie charts do not support a series dimension.');
        }

        if (1 !== \count($query->resolvedChartMetricKeys())) {
            throw InvalidAnalysisConfigurationException::withMessage('Pie charts require exactly one metric.');
        }

        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        if (AnalysisDimensionType::Temporal === $primary->type) {
            throw InvalidAnalysisConfigurationException::withMessage('Pie charts are not supported for temporal dimensions.');
        }
    }

    private function validateHeatmapQuery(AnalysisQuery $query): void
    {
        if (null === $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('Heatmaps require a series dimension.');
        }

        if (1 !== \count($query->resolvedChartMetricKeys())) {
            throw InvalidAnalysisConfigurationException::withMessage('Heatmaps require exactly one metric.');
        }
    }

    private function validateByMetricQuery(AnalysisQuery $query, GenericAnalysisChartType $chartType): void
    {
        $chartMetrics = $query->resolvedChartMetricKeys();
        if (\count($chartMetrics) < 2) {
            throw InvalidAnalysisConfigurationException::withMessage('Metric comparison mode requires at least two metrics.');
        }

        if (\count($chartMetrics) > self::BY_METRIC_MAX_SERIES) {
            throw InvalidAnalysisConfigurationException::withMessage(sprintf('Metric comparison mode supports at most %d metrics.', self::BY_METRIC_MAX_SERIES));
        }

        if (\in_array($chartType, [
            GenericAnalysisChartType::StackedBar,
            GenericAnalysisChartType::PercentStackedBar,
            GenericAnalysisChartType::Pie,
            GenericAnalysisChartType::Heatmap,
        ], true)) {
            throw InvalidAnalysisConfigurationException::withMessage(sprintf('Chart type "%s" is not supported in metric comparison mode.', $chartType->value));
        }

        $formats = [];
        foreach ($chartMetrics as $key) {
            if (!$this->metricRegistry->has($key)) {
                continue;
            }
            $formats[] = $this->metricRegistry->get($key)->defaultFormat->value;
        }
        if (\count(array_unique($formats)) > 1) {
            throw InvalidAnalysisConfigurationException::withMessage('Metric comparison requires metrics with the same format.');
        }
    }

    private function adjustForByMetric(
        AnalysisQuery $query,
        GenericAnalysisChartRecommendation $recommendation,
    ): GenericAnalysisChartRecommendation {
        $allowed = [
            GenericAnalysisChartType::Line,
            GenericAnalysisChartType::Bar,
            GenericAnalysisChartType::GroupedBar,
            GenericAnalysisChartType::HorizontalBar,
            GenericAnalysisChartType::Table,
        ];

        $default = GenericAnalysisChartType::Line;
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        if (AnalysisDimensionType::Temporal !== $primary->type) {
            $default = GenericAnalysisChartType::GroupedBar;
        }

        if ($query->chartType instanceof GenericAnalysisChartType && \in_array($query->chartType, $allowed, true)) {
            $default = $query->chartType;
        }

        return new GenericAnalysisChartRecommendation(
            hasChart: $recommendation->hasChart,
            defaultChartType: $default,
            allowedChartTypes: $allowed,
            warnings: $recommendation->warnings,
            reason: 'by_metric',
        );
    }

    private function adjustForExplicitChartType(
        AnalysisQuery $query,
        GenericAnalysisChartRecommendation $recommendation,
    ): GenericAnalysisChartRecommendation {
        if (!$query->chartType instanceof GenericAnalysisChartType) {
            return $recommendation;
        }

        $allowed = $recommendation->allowedChartTypes;
        if (!\in_array($query->chartType, $allowed, true)) {
            if (GenericAnalysisChartType::Pie === $query->chartType && $this->canSupportPie($query)) {
                $allowed[] = GenericAnalysisChartType::Pie;
            } elseif (GenericAnalysisChartType::Heatmap === $query->chartType && null !== $query->seriesDimensionKey) {
                $allowed[] = GenericAnalysisChartType::Heatmap;
            } else {
                return $recommendation;
            }
        }

        return new GenericAnalysisChartRecommendation(
            hasChart: $recommendation->hasChart,
            defaultChartType: $query->chartType,
            allowedChartTypes: array_values(array_unique($allowed, SORT_REGULAR)),
            warnings: $recommendation->warnings,
            reason: $recommendation->reason,
        );
    }

    private function canSupportPie(AnalysisQuery $query): bool
    {
        if (null !== $query->seriesDimensionKey) {
            return false;
        }

        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        if (AnalysisDimensionType::Temporal === $primary->type) {
            return false;
        }

        return AnalysisDimensionType::Numeric !== $primary->type;
    }

    public function isPieBucketCountValid(int $bucketCount): bool
    {
        return $bucketCount > 0 && $bucketCount <= self::PIE_MAX_BUCKETS;
    }

    public function metricUsesPercentScale(string $metricKey): bool
    {
        if (!$this->metricRegistry->has($metricKey)) {
            return false;
        }

        return MetricFormat::Percent === $this->metricRegistry->get($metricKey)->defaultFormat;
    }
}
