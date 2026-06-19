<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisChartRecommendation;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisChartRecommendationService
{
    private const int MANY_BUCKETS_THRESHOLD = 24;
    private const int MANY_SERIES_WARNING_THRESHOLD = 8;

    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    public function recommend(AnalysisQuery $query, NormalizedAnalysisResult $result): GenericAnalysisChartRecommendation
    {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $series = null !== $query->seriesDimensionKey
            ? $this->dimensionRegistry->get($query->seriesDimensionKey)
            : null;

        $labels = $this->extractLabels($result);
        $seriesCount = $this->countDistinctSeries($result);
        $warnings = [];

        if (0 === $result->grandTotal || [] === $labels) {
            return new GenericAnalysisChartRecommendation(
                hasChart: false,
                defaultChartType: GenericAnalysisChartType::Bar,
                allowedChartTypes: [],
                warnings: [],
                reason: 'empty_data',
            );
        }

        if (AnalysisDimensionType::Numeric === $primary->type) {
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.numeric_dimension');

            return new GenericAnalysisChartRecommendation(
                hasChart: false,
                defaultChartType: GenericAnalysisChartType::Table,
                allowedChartTypes: [GenericAnalysisChartType::Table],
                warnings: $warnings,
                reason: 'numeric_primary',
            );
        }

        if ('histogram' === $primary->recommendedChartType) {
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.histogram_not_supported');

            return new GenericAnalysisChartRecommendation(
                hasChart: false,
                defaultChartType: GenericAnalysisChartType::Table,
                allowedChartTypes: [GenericAnalysisChartType::Table],
                warnings: $warnings,
                reason: 'histogram_primary',
            );
        }

        if ('hour' === $primary->key && $series instanceof AnalysisDimension && 'weekday' === $series->key) {
            $allowed = [GenericAnalysisChartType::Heatmap, GenericAnalysisChartType::StackedBar, GenericAnalysisChartType::GroupedBar, GenericAnalysisChartType::Table];

            return new GenericAnalysisChartRecommendation(
                hasChart: true,
                defaultChartType: GenericAnalysisChartType::Heatmap,
                allowedChartTypes: $allowed,
                warnings: $warnings,
                reason: 'hour_weekday_heatmap',
            );
        }

        if ('weekday' === $primary->key && $series instanceof AnalysisDimension && 'hour' === $series->key) {
            $allowed = [GenericAnalysisChartType::Heatmap, GenericAnalysisChartType::StackedBar, GenericAnalysisChartType::GroupedBar, GenericAnalysisChartType::Table];

            return new GenericAnalysisChartRecommendation(
                hasChart: true,
                defaultChartType: GenericAnalysisChartType::Heatmap,
                allowedChartTypes: $allowed,
                warnings: $warnings,
                reason: 'weekday_hour_heatmap',
            );
        }

        if ($seriesCount > self::MANY_SERIES_WARNING_THRESHOLD) {
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.many_series');
        }

        $manyBuckets = \count($labels) > self::MANY_BUCKETS_THRESHOLD;

        if ($series instanceof AnalysisDimension) {
            return $this->recommendWithSeries($primary, $manyBuckets, $warnings);
        }

        return $this->recommendWithoutSeries($primary, $manyBuckets, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    private function recommendWithSeries(
        AnalysisDimension $primary,
        bool $manyBuckets,
        array $warnings,
    ): GenericAnalysisChartRecommendation {
        if (AnalysisDimensionType::Temporal === $primary->type) {
            $allowed = [
                GenericAnalysisChartType::StackedBar,
                GenericAnalysisChartType::GroupedBar,
                GenericAnalysisChartType::Line,
                GenericAnalysisChartType::PercentStackedBar,
                GenericAnalysisChartType::Table,
            ];

            if ($manyBuckets) {
                $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.many_buckets');
            }

            return new GenericAnalysisChartRecommendation(
                hasChart: true,
                defaultChartType: GenericAnalysisChartType::StackedBar,
                allowedChartTypes: $allowed,
                warnings: $warnings,
                reason: 'temporal_with_series',
            );
        }

        $allowed = [
            GenericAnalysisChartType::StackedBar,
            GenericAnalysisChartType::GroupedBar,
            GenericAnalysisChartType::Bar,
            GenericAnalysisChartType::Table,
        ];
        if ($manyBuckets) {
            $allowed[] = GenericAnalysisChartType::HorizontalBar;
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.many_buckets');
        }

        return new GenericAnalysisChartRecommendation(
            hasChart: true,
            defaultChartType: GenericAnalysisChartType::StackedBar,
            allowedChartTypes: $allowed,
            warnings: $warnings,
            reason: 'categorical_with_series',
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function recommendWithoutSeries(
        AnalysisDimension $primary,
        bool $manyBuckets,
        array $warnings,
    ): GenericAnalysisChartRecommendation {
        if (AnalysisDimensionType::Temporal === $primary->type) {
            $allowed = [GenericAnalysisChartType::Bar, GenericAnalysisChartType::Line, GenericAnalysisChartType::Table];
            if ($manyBuckets) {
                $allowed[] = GenericAnalysisChartType::HorizontalBar;
                $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.many_buckets');
            }

            return new GenericAnalysisChartRecommendation(
                hasChart: true,
                defaultChartType: $manyBuckets ? GenericAnalysisChartType::HorizontalBar : GenericAnalysisChartType::Bar,
                allowedChartTypes: $allowed,
                warnings: $warnings,
                reason: 'temporal_no_series',
            );
        }

        $allowed = [GenericAnalysisChartType::Bar, GenericAnalysisChartType::Table];
        if ($manyBuckets) {
            $allowed[] = GenericAnalysisChartType::HorizontalBar;
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.many_buckets');
        } else {
            $allowed[] = GenericAnalysisChartType::HorizontalBar;
        }

        if ('pie' === $primary->recommendedChartType && !$manyBuckets) {
            $allowed[] = GenericAnalysisChartType::Pie;
        }

        $default = $manyBuckets ? GenericAnalysisChartType::HorizontalBar : GenericAnalysisChartType::Bar;
        if ('pie' === $primary->recommendedChartType && !$manyBuckets) {
            $default = GenericAnalysisChartType::Pie;
        }

        return new GenericAnalysisChartRecommendation(
            hasChart: true,
            defaultChartType: $default,
            allowedChartTypes: $allowed,
            warnings: $warnings,
            reason: 'categorical_no_series',
        );
    }

    /**
     * @return list<string>
     */
    private function extractLabels(NormalizedAnalysisResult $result): array
    {
        $labels = $result->chartData['labels'] ?? null;
        if (!\is_array($labels)) {
            return [];
        }

        return array_values(array_filter($labels, static fn (mixed $label): bool => \is_string($label) && '' !== $label));
    }

    private function countDistinctSeries(NormalizedAnalysisResult $result): int
    {
        $keys = [];
        foreach ($result->rows as $row) {
            if (null !== $row->seriesKey) {
                $keys[$row->seriesKey] = true;
            }
        }

        if ([] !== $keys) {
            return \count($keys);
        }

        $series = $result->chartData['series'] ?? null;
        if (!\is_array($series)) {
            return 0;
        }

        return \count($series);
    }
}
