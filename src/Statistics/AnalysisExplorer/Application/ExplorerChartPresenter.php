<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\GenericAnalysis\Application\ChartPrimaryBucketLimiter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerChartPresenter
{
    public function __construct(
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private MetricRegistry $metricRegistry,
        private ChartPrimaryBucketLimiter $primaryBucketLimiter,
        private TranslatorInterface $translator,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildSpecs(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        if ([] === $result->rows) {
            return [];
        }

        $spec = $result->hasSeries()
            ? match ($presentation->chartType) {
                ChartPresentationType::BoxPlot => $this->buildMultiSeriesBoxPlotSpec($result, $presentation),
                ChartPresentationType::Heatmap => $this->buildHeatmapSpec($result, $presentation),
                default => $this->buildMultiSeriesSpec($result, $presentation),
            }
        : ($this->isMetricComparisonChart($result, $presentation)
            ? $this->buildMetricComparisonSpec($result)
            : $this->buildSingleSeriesSpec($result, $presentation));

        return [
            $presentation->chartType->value => $spec,
        ];
    }

    public function defaultChartType(PresentationConfig $presentation): string
    {
        return $presentation->chartType->value;
    }

    public function hasChart(AnalysisRunResult $result): bool
    {
        return [] !== $result->rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetricComparisonSpec(AnalysisRunResult $result): array
    {
        $row = $result->rows[0] ?? null;
        $labels = [];
        $values = [];

        foreach ($result->metricKeys as $metricKey) {
            $labels[] = $this->metricLabel($metricKey);
            $values[] = $row instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow
                ? $row->visualValue($metricKey)
                : $result->totalFor($metricKey);
        }

        $visualMetricKey = $result->visualMetricKey;

        return [
            'chartType' => 'bar',
            'labels' => $labels,
            'values' => $values,
            'counts' => $values,
            'barGrouped' => true,
            'valueLabel' => $this->metricLabel($visualMetricKey),
            'valueFormat' => $this->metricFormat($visualMetricKey),
            'percentScale' => 'percent' === $this->metricFormat($visualMetricKey),
            'xAxisLabel' => $this->translator->trans('stats.analysis_explorer.metric_comparison.axis'),
            'yAxisLabel' => $this->metricLabel($visualMetricKey),
        ];
    }

    private function isMetricComparisonChart(AnalysisRunResult $result, PresentationConfig $presentation): bool
    {
        return ChartPresentationType::GroupedBar === $presentation->chartType
            && \count($result->metricKeys) > 1
            && AnalysisDimensionKey::PeriodTotal === $result->rowAxis->dimensionKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSingleSeriesSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        if (ChartPresentationType::BoxPlot === $presentation->chartType) {
            return $this->buildBoxPlotSpec($result, $presentation);
        }

        $labels = [];
        $values = [];

        foreach ($result->rows as $row) {
            $labels[] = $row->bucketLabel;
            $values[] = $row->visualValue($result->visualMetricKey);
        }

        [$labels, $values] = $this->applyRowLimit($labels, $values, [], $presentation, $result->rowAxis);

        return [
            'chartType' => match ($presentation->chartType) {
                ChartPresentationType::Line => 'line',
                default => 'bar',
            },
            'labels' => $labels,
            'values' => $values,
            'counts' => $values,
            'valueLabel' => $this->metricLabel($result->visualMetricKey),
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
            'percentScale' => 'percent' === $this->metricFormat($result->visualMetricKey),
            'xAxisLabel' => $this->axisLabel($result->rowAxis),
            'yAxisLabel' => $this->metricLabel($result->visualMetricKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBoxPlotSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $points = [];
        foreach ($result->rows as $row) {
            if (!$row->boxPlot instanceof BoxPlotStats) {
                continue;
            }

            $points[] = [
                'x' => $row->bucketLabel,
                'y' => $row->boxPlot->apexValues(),
            ];
        }

        $labels = array_map(static fn (array $point): string => $point['x'], $points);
        $cap = $presentation->chartRowLimit->cap();
        if (null !== $cap && \count($labels) > $cap && !$result->rowAxis->dimensionKey->isTemporalPrimary()) {
            [$limitedLabels] = $this->primaryBucketLimiter->limit(
                $labels,
                array_map(static fn (array $point): float => $point['y'][2] ?? 0.0, $points),
                [],
                $cap,
                includeRemainderBucket: false,
            );
            $limitedLabelSet = array_flip($limitedLabels);
            $points = array_values(array_filter(
                $points,
                static fn (array $point): bool => isset($limitedLabelSet[$point['x']]),
            ));
        }

        return [
            'chartType' => 'boxPlot',
            'series' => [[
                'name' => $this->metricLabel($result->visualMetricKey),
                'type' => 'boxPlot',
                'data' => $points,
            ]],
            'xAxisLabel' => $this->axisLabel($result->rowAxis),
            'yAxisLabel' => $this->metricLabel($result->visualMetricKey),
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMultiSeriesBoxPlotSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $matrix = AnalysisMatrix::fromRunResult($result);

        /** @var array<string, array<string, \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow>> $rowsByCell */
        $rowsByCell = [];
        foreach ($result->rows as $row) {
            if (!$row->boxPlot instanceof BoxPlotStats) {
                continue;
            }

            $rowsByCell[$row->bucket][$row->seriesKey ?? ''] = $row;
        }

        $orderedRowKeys = $matrix->orderedRowKeys;
        $rowLabels = $matrix->chartLabels();
        $cap = $presentation->chartRowLimit->cap();
        if (null !== $cap && \count($rowLabels) > $cap && !$result->rowAxis->dimensionKey->isTemporalPrimary()) {
            $rowWeights = [];
            foreach ($orderedRowKeys as $rowKey) {
                $weight = 0.0;
                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $weight = max($weight, $matrix->valueFor($rowKey, $colKey, $result->visualMetricKey));
                }
                $rowWeights[] = $weight;
            }

            [$limitedRowLabels] = $this->primaryBucketLimiter->limit(
                $rowLabels,
                $rowWeights,
                [],
                $cap,
                includeRemainderBucket: false,
            );
            $limitedLabelSet = array_flip($limitedRowLabels);
            $orderedRowKeys = array_values(array_filter(
                $orderedRowKeys,
                static fn (string $rowKey): bool => isset($limitedLabelSet[$matrix->rowLabels[$rowKey]]),
            ));
        }

        $chartSeries = [];
        $columnKeys = [] !== $matrix->orderedColumnKeys
            ? $matrix->orderedColumnKeys
            : array_values(array_unique(array_map(
                static fn (\App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow $row): string => $row->seriesKey ?? '',
                $result->rows,
            )));

        foreach ($columnKeys as $colKey) {
            $points = [];
            foreach ($orderedRowKeys as $rowKey) {
                $row = $rowsByCell[$rowKey][$colKey] ?? null;
                if (!$row instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow
                    || !$row->boxPlot instanceof BoxPlotStats) {
                    continue;
                }

                $points[] = [
                    'x' => $row->bucketLabel,
                    'y' => $row->boxPlot->apexValues(),
                ];
            }

            if ([] === $points) {
                continue;
            }

            $seriesLabel = $matrix->columnLabels[$colKey] ?? $colKey;
            foreach ($rowsByCell as $rowBuckets) {
                if (isset($rowBuckets[$colKey]) && null !== $rowBuckets[$colKey]->seriesLabel) {
                    $seriesLabel = $rowBuckets[$colKey]->seriesLabel;
                    break;
                }
            }

            $chartSeries[] = [
                'name' => $seriesLabel,
                'type' => 'boxPlot',
                'data' => $points,
            ];
        }

        $columnAxis = $result->columnAxis;

        return [
            'chartType' => 'boxPlot',
            'series' => $chartSeries,
            'multiSeries' => true,
            'xAxisLabel' => $this->axisLabel($result->rowAxis),
            'yAxisLabel' => $this->metricLabel($result->visualMetricKey),
            'seriesAxisLabel' => $columnAxis instanceof AnalysisAxisRef ? $this->axisLabel($columnAxis) : null,
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMultiSeriesSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $matrix = AnalysisMatrix::fromRunResult($result);

        $chartType = match ($presentation->chartType) {
            ChartPresentationType::Line => 'line',
            default => 'bar',
        };

        $labels = $matrix->chartLabels();
        $series = $matrix->chartSeries($result->visualMetricKey);
        [$labels, , $series] = $this->applyRowLimit($labels, [], $series, $presentation, $result->rowAxis);

        $spec = [
            'chartType' => $chartType,
            'labels' => $labels,
            'series' => $series,
            'valueLabel' => $this->metricLabel($result->visualMetricKey),
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
            'percentScale' => 'percent' === $this->metricFormat($result->visualMetricKey),
        ];

        if (ChartPresentationType::GroupedBar === $presentation->chartType) {
            $spec['barGrouped'] = true;
        }

        if (ChartPresentationType::StackedBar === $presentation->chartType) {
            $spec['stacked'] = true;
        }

        $spec['xAxisLabel'] = $this->axisLabel($result->rowAxis);
        $spec['yAxisLabel'] = $this->metricLabel($result->visualMetricKey);

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeatmapSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $matrix = AnalysisMatrix::fromRunResult($result);
        $rowLabels = $matrix->chartLabels();
        $columnLabels = $matrix->heatmapColumnLabels();
        $matrixData = $matrix->heatmapMatrix($result->visualMetricKey);
        [$rowLabels, $matrixData] = $this->applyMatrixRowLimit(
            $rowLabels,
            $matrixData,
            $presentation,
            $result->rowAxis,
        );

        $columnAxis = $result->columnAxis;

        return [
            'chartType' => 'heatmap',
            'rowLabels' => $rowLabels,
            'columnLabels' => $columnLabels,
            'matrix' => $matrixData,
            'valueLabel' => $this->metricLabel($result->visualMetricKey),
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
            'percentScale' => 'percent' === $this->metricFormat($result->visualMetricKey),
            'xAxisLabel' => $columnAxis instanceof AnalysisAxisRef ? $this->axisLabel($columnAxis) : null,
            'yAxisLabel' => $this->axisLabel($result->rowAxis),
        ];
    }

    /**
     * @param list<string>      $rowLabels
     * @param list<list<float>> $matrix
     *
     * @return array{0: list<string>, 1: list<list<float>>}
     */
    private function applyMatrixRowLimit(
        array $rowLabels,
        array $matrix,
        PresentationConfig $presentation,
        AnalysisAxisRef $rowAxis,
    ): array {
        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            return [$rowLabels, $matrix];
        }

        $cap = $presentation->chartRowLimit->cap();
        if (null === $cap || \count($rowLabels) <= $cap) {
            return [$rowLabels, $matrix];
        }

        $rowTotals = array_map(
            array_sum(...),
            $matrix,
        );
        [$limitedLabels] = $this->primaryBucketLimiter->limit(
            $rowLabels,
            $rowTotals,
            [],
            $cap,
            includeRemainderBucket: false,
        );

        $limitedMatrix = [];
        foreach ($limitedLabels as $label) {
            $index = array_search($label, $rowLabels, true);
            if (false === $index) {
                continue;
            }
            $limitedMatrix[] = $matrix[$index];
        }

        return [$limitedLabels, $limitedMatrix];
    }

    /**
     * @param list<string>                                     $labels
     * @param list<int|float>                                  $values
     * @param list<array{name: string, data: list<int|float>}> $series
     *
     * @return array{0: list<string>, 1: list<int|float>, 2: list<array{name: string, data: list<int|float>}>}
     */
    private function applyRowLimit(
        array $labels,
        array $values,
        array $series,
        PresentationConfig $presentation,
        AnalysisAxisRef $rowAxis,
    ): array {
        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            return [$labels, $values, $series];
        }

        $cap = $presentation->chartRowLimit->cap();
        if (null === $cap || \count($labels) <= $cap) {
            return [$labels, $values, $series];
        }

        return $this->primaryBucketLimiter->limit($labels, $values, $series, $cap, includeRemainderBucket: false);
    }

    private function axisLabel(AnalysisAxisRef $axis): string
    {
        if ($axis->dimensionKey->isTemporalPrimary()) {
            return $this->temporalDimensionLabel($axis->resolvedGrain());
        }

        return $this->translator->trans('stats.analysis_explorer.dimension.'.$axis->dimensionKey->value);
    }

    private function temporalDimensionLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
            default => $this->translator->trans('stats.analysis_explorer.dimension.month'),
        };
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        $profile = $this->profileRegistry->profileFor($metricKey);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey);
        }

        return $this->metricRegistry->get($this->metricKeyMapper->toRegistryKey($metricKey))->label;
    }

    private function metricFormat(AnalysisMetricKey $metricKey): string
    {
        if ($metricKey->isDistributionProfile()) {
            return $this->metricRegistry->get($this->profileRegistry->formatRegistryKeyFor($metricKey))->defaultFormat->value;
        }

        return $this->metricRegistry->get($this->metricKeyMapper->toRegistryKey($metricKey))->defaultFormat->value;
    }
}
