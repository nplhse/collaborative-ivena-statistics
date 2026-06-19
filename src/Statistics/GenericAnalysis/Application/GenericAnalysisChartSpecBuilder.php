<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisReducedChartData;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final readonly class GenericAnalysisChartSpecBuilder
{
    public function __construct(
        private GenericAnalysisChartDataReducer $chartDataReducer,
        private MetricRegistry $metricRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildSpec(
        GenericAnalysisChartType $chartType,
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
        ?int $primaryBucketCap = 5,
    ): ?array {
        if (!$chartType->supportsApexChart()) {
            return null;
        }

        $data = $this->chartDataReducer->reduce($query, $result, $primaryBucketCap);
        if ([] === $data->labels) {
            return null;
        }

        $percentScale = $this->usesPercentScale($result->visualMetricKey);

        return match ($chartType) {
            GenericAnalysisChartType::Line => $this->buildLineSpec($data, $percentScale),
            GenericAnalysisChartType::GroupedBar => $this->buildGroupedBarSpec($data, $percentScale),
            GenericAnalysisChartType::PercentStackedBar => $this->buildPercentStackedBarSpec($data),
            GenericAnalysisChartType::HorizontalBar => $this->buildHorizontalBarSpec($data, $percentScale),
            GenericAnalysisChartType::StackedBar => $this->buildStackedBarSpec($data, $percentScale),
            GenericAnalysisChartType::Bar => $this->buildBarSpec($data, $percentScale),
            GenericAnalysisChartType::Pie => $this->buildPieSpec($data, $percentScale),
            GenericAnalysisChartType::Heatmap => $this->buildHeatmapSpec($data),
            default => null,
        };
    }

    /**
     * @param list<GenericAnalysisChartType> $allowedTypes
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildSpecsForTypes(
        array $allowedTypes,
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
        ?int $primaryBucketCap = 5,
    ): array {
        $specs = [];
        foreach ($allowedTypes as $type) {
            if (!$type->supportsApexChart()) {
                continue;
            }
            $spec = $this->buildSpec($type, $query, $result, $primaryBucketCap);
            if (null !== $spec) {
                $specs[$type->value] = $spec;
            }
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPieSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        $values = [];
        if (null !== $data->counts && [] !== $data->counts) {
            $values = $data->counts;
        } elseif (null !== $data->series && [] !== $data->series) {
            $values = $data->series[0]['data'] ?? [];
        }

        $spec = [
            'chartType' => 'pie',
            'labels' => $data->labels,
            'series' => [
                [
                    'name' => 'Total',
                    'data' => $values,
                ],
            ],
        ];

        return $this->applyPercentScale($spec, $percentScale);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildHeatmapSpec(GenericAnalysisReducedChartData $data): ?array
    {
        if (null === $data->series || [] === $data->series) {
            return null;
        }

        $rowLabels = $data->labels;
        $columnLabels = array_map(
            static fn (array $item): string => $item['name'],
            $data->series,
        );

        $matrix = [];
        foreach (array_keys($rowLabels) as $rowIndex) {
            $row = [];
            foreach ($data->series as $seriesItem) {
                $row[] = $seriesItem['data'][$rowIndex] ?? 0;
            }
            $matrix[] = $row;
        }

        return [
            'chartType' => 'heatmap',
            'rowLabels' => $rowLabels,
            'columnLabels' => $columnLabels,
            'matrix' => $matrix,
            'valueFormat' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBarSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        if (null !== $data->series && [] !== $data->series) {
            $spec = [
                'chartType' => 'bar',
                'labels' => $data->labels,
                'series' => $data->series,
            ];

            return $this->applyPercentScale($spec, $percentScale);
        }

        $spec = [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'counts' => $data->counts ?? [],
        ];

        return $this->applyPercentScale($spec, $percentScale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStackedBarSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        if (null === $data->series || [] === $data->series) {
            return $this->buildBarSpec($data, $percentScale);
        }

        $spec = [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $data->series,
        ];

        return $this->applyPercentScale($spec, $percentScale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGroupedBarSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        if (null === $data->series || [] === $data->series) {
            return $this->buildBarSpec($data, $percentScale);
        }

        $spec = [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $data->series,
            'barGrouped' => true,
        ];

        return $this->applyPercentScale($spec, $percentScale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLineSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        if (null !== $data->series && [] !== $data->series) {
            $spec = [
                'chartType' => 'line',
                'labels' => $data->labels,
                'series' => $data->series,
            ];

            return $this->applyPercentScale($spec, $percentScale);
        }

        $spec = [
            'chartType' => 'line',
            'labels' => $data->labels,
            'counts' => $data->counts ?? [],
        ];

        return $this->applyPercentScale($spec, $percentScale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHorizontalBarSpec(GenericAnalysisReducedChartData $data, bool $percentScale): array
    {
        $spec = $this->buildBarSpec($data, $percentScale);
        $spec['horizontal'] = true;

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPercentStackedBarSpec(GenericAnalysisReducedChartData $data): array
    {
        $percentSeries = $this->buildPercentSeriesFromCounts($data);
        if ([] === $percentSeries) {
            return $this->buildBarSpec($data, false);
        }

        return [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $percentSeries,
            'percentScale' => true,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private function applyPercentScale(array $spec, bool $percentScale): array
    {
        if ($percentScale) {
            $spec['percentScale'] = true;
        }

        return $spec;
    }

    private function usesPercentScale(string $visualMetricKey): bool
    {
        if (!$this->metricRegistry->has($visualMetricKey)) {
            return false;
        }

        return MetricFormat::Percent === $this->metricRegistry->get($visualMetricKey)->defaultFormat;
    }

    /**
     * @return list<array{name: string, data: list<float>}>
     */
    private function buildPercentSeriesFromCounts(GenericAnalysisReducedChartData $data): array
    {
        if (null === $data->series || [] === $data->series) {
            return [];
        }

        $labelCount = \count($data->labels);
        $seriesOutput = [];

        foreach ($data->series as $item) {
            $seriesOutput[] = [
                'name' => $item['name'],
                'data' => array_fill(0, $labelCount, 0.0),
            ];
        }

        for ($labelIndex = 0; $labelIndex < $labelCount; ++$labelIndex) {
            $bucketTotal = 0.0;
            foreach ($data->series as $item) {
                $bucketTotal += (float) ($item['data'][$labelIndex] ?? 0);
            }

            foreach ($data->series as $seriesIndex => $item) {
                $value = $item['data'][$labelIndex] ?? 0;
                $percent = $bucketTotal > 0
                    ? round(((float) $value / $bucketTotal) * 100.0, 2)
                    : 0.0;
                $seriesOutput[$seriesIndex]['data'][$labelIndex] = $percent;
            }
        }

        /** @var list<array{name: string, data: list<float>}> $result */
        $result = [];
        foreach ($seriesOutput as $item) {
            $result[] = [
                'name' => $item['name'],
                'data' => array_values($item['data']),
            ];
        }

        return $result;
    }
}
