<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\MultiSeriesPivot;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final readonly class ExplorerChartPresenter
{
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
        $counts = [];

        foreach ($result->rows as $row) {
            $labels[] = $row->bucketLabel;
            $counts[] = $row->value;
        }

        return [
            'chartType' => match ($presentation->chartType) {
                ChartPresentationType::Line => 'line',
                default => 'bar',
            },
            'labels' => $labels,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMultiSeriesSpec(AnalysisRunResult $result, PresentationConfig $presentation): array
    {
        $pivot = MultiSeriesPivot::fromResult($result);

        $chartType = match ($presentation->chartType) {
            ChartPresentationType::Line => 'line',
            default => 'bar',
        };

        $spec = [
            'chartType' => $chartType,
            'labels' => $pivot->labels,
            'series' => $pivot->series,
        ];

        if (ChartPresentationType::GroupedBar === $presentation->chartType) {
            $spec['barGrouped'] = true;
        }

        return $spec;
    }
}
