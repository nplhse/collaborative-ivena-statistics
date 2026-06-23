<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisReducedChartData;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisChartDataReducer
{
    private const int TOP_SERIES_LIMIT = 5;

    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private ChartPrimaryBucketLimiter $primaryBucketLimiter,
        private TranslatorInterface $translator,
    ) {
    }

    public function reduce(
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
        ?int $primaryBucketCap = 5,
    ): GenericAnalysisReducedChartData {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);

        $labels = $this->extractLabels($result);
        $counts = $this->extractVisualValues($result, $labels);
        $series = $this->extractSeries($result, $result->visualMetricKey);

        $limitedSeries = false;
        $limitedBuckets = false;

        if (\count($series) > self::TOP_SERIES_LIMIT) {
            $series = $this->limitSeries($series, $labels, self::TOP_SERIES_LIMIT);
            $limitedSeries = true;
        }

        if (null !== $primaryBucketCap && $this->shouldLimitPrimaryBuckets($primary, \count($labels), $primaryBucketCap)) {
            [$labels, $counts, $series] = $this->primaryBucketLimiter->limit(
                $labels,
                $this->toIntCounts($counts),
                $series,
                $primaryBucketCap,
            );
            $limitedBuckets = true;
        }

        return new GenericAnalysisReducedChartData(
            labels: $labels,
            counts: [] !== $series ? null : $counts,
            series: [] !== $series ? $series : null,
            limitedPrimaryBuckets: $limitedBuckets,
            limitedSeries: $limitedSeries,
        );
    }

    private function shouldLimitPrimaryBuckets(AnalysisDimension $primary, int $labelCount, ?int $cap): bool
    {
        if (null === $cap || $labelCount <= $cap) {
            return false;
        }

        return AnalysisDimensionType::Temporal !== $primary->type;
    }

    /**
     * @param list<array{name: string, data: list<int|float>}> $series
     * @param list<string>                                     $labels
     *
     * @return list<array{name: string, data: list<int|float>}>
     */
    private function limitSeries(array $series, array $labels, int $cap): array
    {
        $labelCount = \count($labels);
        $totals = [];
        foreach ($series as $index => $item) {
            $totals[$index] = array_sum($item['data']);
        }

        arsort($totals);
        $topIndices = array_slice(array_keys($totals), 0, $cap);

        $restData = array_fill(0, $labelCount, 0.0);
        foreach ($series as $index => $item) {
            if (\in_array($index, $topIndices, true)) {
                continue;
            }
            foreach ($item['data'] as $labelIndex => $value) {
                $restData[$labelIndex] += (float) $value;
            }
        }

        $limited = [];
        foreach ($topIndices as $index) {
            $limited[] = $series[$index];
        }

        if (array_sum($restData) > 0) {
            $limited[] = [
                'name' => $this->remainderSeriesLabel(),
                'data' => $restData,
            ];
        }

        return $limited;
    }

    private function remainderSeriesLabel(): string
    {
        return $this->translator->trans('stats.generic_analysis.chart.remainder_series');
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

        return array_values(array_filter($labels, \is_string(...)));
    }

    /**
     * @param list<string> $labels
     *
     * @return list<int|float>
     */
    private function extractVisualValues(NormalizedAnalysisResult $result, array $labels): array
    {
        $visualKey = $result->visualMetricKey;
        $values = $result->chartData['values'] ?? null;
        if (\is_array($values)) {
            return array_values(array_map(
                fn (mixed $v): int|float => $this->chartScalar($v, $visualKey),
                $values,
            ));
        }

        $counts = array_fill(0, \count($labels), 0);
        foreach ($result->rows as $row) {
            if (null !== $row->seriesKey) {
                continue;
            }
            $index = array_search($row->bucketLabel, $labels, true);
            if (false !== $index) {
                $counts[$index] = $this->chartScalar($row->metrics[$visualKey] ?? 0, $visualKey);
            }
        }

        return array_values($counts);
    }

    private function chartScalar(mixed $value, string $visualKey): int|float
    {
        if (!\is_int($value) && !\is_float($value)) {
            return 0;
        }

        if (!$this->metricRegistry->has($visualKey)) {
            return (int) round((float) $value);
        }

        $format = $this->metricRegistry->get($visualKey)->defaultFormat;

        return MetricFormat::Integer === $format
            ? (int) round((float) $value)
            : (float) $value;
    }

    /**
     * @param list<int|float> $counts
     *
     * @return list<int>
     */
    private function toIntCounts(array $counts): array
    {
        return array_map(
            static fn (int|float $value): int => (int) round((float) $value),
            $counts,
        );
    }

    /**
     * @return list<array{name: string, data: list<int|float>}>
     */
    private function extractSeries(NormalizedAnalysisResult $result, string $visualKey): array
    {
        $raw = $result->chartData['series'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $series = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $name = $item['name'] ?? '';
            $data = $item['data'] ?? [];
            if (!\is_string($name) || !\is_array($data)) {
                continue;
            }
            $series[] = [
                'name' => $name,
                'data' => array_values(array_map(
                    fn (mixed $v): int|float => $this->chartScalar($v, $visualKey),
                    $data,
                )),
            ];
        }

        return $series;
    }
}
