<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisReducedChartData;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;

final readonly class GenericAnalysisChartSpecBuilder
{
    public function __construct(
        private GenericAnalysisChartDataReducer $chartDataReducer,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildSpec(
        GenericAnalysisChartType $chartType,
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
    ): ?array {
        if (!$chartType->supportsApexChart()) {
            return null;
        }

        $data = $this->chartDataReducer->reduce($query, $result);
        if ([] === $data->labels) {
            return null;
        }

        return match ($chartType) {
            GenericAnalysisChartType::Line => $this->buildLineSpec($data),
            GenericAnalysisChartType::GroupedBar => $this->buildGroupedBarSpec($data),
            GenericAnalysisChartType::PercentStackedBar => $this->buildPercentStackedBarSpec($data),
            GenericAnalysisChartType::HorizontalBar => $this->buildHorizontalBarSpec($data),
            GenericAnalysisChartType::StackedBar => $this->buildStackedBarSpec($data),
            GenericAnalysisChartType::Bar => $this->buildBarSpec($data),
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
    ): array {
        $specs = [];
        foreach ($allowedTypes as $type) {
            if (!$type->supportsApexChart()) {
                continue;
            }
            $spec = $this->buildSpec($type, $query, $result);
            if (null !== $spec) {
                $specs[$type->value] = $spec;
            }
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBarSpec(GenericAnalysisReducedChartData $data): array
    {
        if (null !== $data->series && [] !== $data->series) {
            return [
                'chartType' => 'bar',
                'labels' => $data->labels,
                'series' => $data->series,
            ];
        }

        return [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'counts' => $data->counts ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStackedBarSpec(GenericAnalysisReducedChartData $data): array
    {
        if (null === $data->series || [] === $data->series) {
            return $this->buildBarSpec($data);
        }

        return [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $data->series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGroupedBarSpec(GenericAnalysisReducedChartData $data): array
    {
        if (null === $data->series || [] === $data->series) {
            return $this->buildBarSpec($data);
        }

        return [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $data->series,
            'barGrouped' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLineSpec(GenericAnalysisReducedChartData $data): array
    {
        if (null !== $data->series && [] !== $data->series) {
            return [
                'chartType' => 'line',
                'labels' => $data->labels,
                'series' => $data->series,
            ];
        }

        return [
            'chartType' => 'line',
            'labels' => $data->labels,
            'counts' => $data->counts ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHorizontalBarSpec(GenericAnalysisReducedChartData $data): array
    {
        $spec = $this->buildBarSpec($data);
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
            return $this->buildBarSpec($data);
        }

        return [
            'chartType' => 'bar',
            'labels' => $data->labels,
            'series' => $percentSeries,
            'percentScale' => true,
        ];
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
            $bucketTotal = 0;
            foreach ($data->series as $item) {
                $bucketTotal += $item['data'][$labelIndex] ?? 0;
            }

            foreach ($data->series as $seriesIndex => $item) {
                $value = $item['data'][$labelIndex] ?? 0;
                $percent = $bucketTotal > 0
                    ? round(((float) $value / (float) $bucketTotal) * 100.0, 2)
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
