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
        /** @var list<string> $labels */
        $labels = [];
        /** @var array<string, string> $labelByBucket */
        $labelByBucket = [];
        /** @var array<string, array<string, int>> $valuesBySeries */
        $valuesBySeries = [];
        /** @var array<string, string> $seriesLabels */
        $seriesLabels = [];

        foreach ($result->rows as $row) {
            if (!isset($labelByBucket[$row->bucket])) {
                $labelByBucket[$row->bucket] = $row->bucketLabel;
                $labels[] = $row->bucketLabel;
            }

            $seriesKey = $row->seriesKey ?? '';
            $seriesLabels[$seriesKey] = $row->seriesLabel ?? $seriesKey;
            $valuesBySeries[$seriesKey][$row->bucket] = $row->value;
        }

        $bucketOrder = array_keys($labelByBucket);
        $series = [];

        foreach ($seriesLabels as $seriesKey => $seriesLabel) {
            $data = [];
            foreach ($bucketOrder as $bucket) {
                $data[] = $valuesBySeries[$seriesKey][$bucket] ?? 0;
            }

            $series[] = [
                'name' => $seriesLabel,
                'data' => $data,
            ];
        }

        $chartType = match ($presentation->chartType) {
            ChartPresentationType::Line => 'line',
            default => 'bar',
        };

        $spec = [
            'chartType' => $chartType,
            'labels' => $labels,
            'series' => $series,
        ];

        if (ChartPresentationType::GroupedBar === $presentation->chartType) {
            $spec['barGrouped'] = true;
        }

        return $spec;
    }
}
