<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

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
        if ([] === $result->dataPoints) {
            return [];
        }

        $labels = [];
        $counts = [];

        foreach ($result->dataPoints as $point) {
            $labels[] = $point->label;
            $counts[] = $point->value;
        }

        $chartType = match ($presentation->chartType) {
            ChartPresentationType::Bar => 'bar',
            ChartPresentationType::Line => 'line',
        };

        $spec = [
            'chartType' => $chartType,
            'labels' => $labels,
            'counts' => $counts,
        ];

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
        return [] !== $result->dataPoints;
    }
}
