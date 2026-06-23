<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerChartPresenter
{
    public function __construct(
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private MetricRegistry $metricRegistry,
        private TranslatorInterface $translator,
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
            ? $this->buildMultiSeriesSpec($result, $presentation)
            : $this->buildSingleSeriesSpec($result, $presentation);

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
    private function buildSingleSeriesSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $labels = [];
        $values = [];

        foreach ($result->rows as $row) {
            $labels[] = $row->bucketLabel;
            $values[] = $row->visualValue($result->visualMetricKey);
        }

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
    private function buildMultiSeriesSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $matrix = AnalysisMatrix::fromRunResult($result);

        $chartType = match ($presentation->chartType) {
            ChartPresentationType::Line => 'line',
            default => 'bar',
        };

        $spec = [
            'chartType' => $chartType,
            'labels' => $matrix->chartLabels(),
            'series' => $matrix->chartSeries($result->visualMetricKey),
            'valueLabel' => $this->metricLabel($result->visualMetricKey),
            'valueFormat' => $this->metricFormat($result->visualMetricKey),
            'percentScale' => 'percent' === $this->metricFormat($result->visualMetricKey),
        ];

        if (ChartPresentationType::GroupedBar === $presentation->chartType) {
            $spec['barGrouped'] = true;
        }

        $spec['xAxisLabel'] = $this->axisLabel($result->rowAxis);
        $spec['yAxisLabel'] = $this->metricLabel($result->visualMetricKey);

        return $spec;
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
        return $this->metricRegistry->get($this->metricKeyMapper->toRegistryKey($metricKey))->label;
    }

    private function metricFormat(AnalysisMetricKey $metricKey): string
    {
        return $this->metricRegistry->get($this->metricKeyMapper->toRegistryKey($metricKey))->defaultFormat->value;
    }
}
