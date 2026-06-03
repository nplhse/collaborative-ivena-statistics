<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableMetricColumn;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final class GenericAnalysisTestFixtures
{
    /**
     * @param array<string, int|float|null> $extraMetrics
     */
    public static function resultRow(
        int|string|float|null $bucket,
        int $count,
        int|string|float|null $series = null,
        array $extraMetrics = [],
    ): AnalysisResultRow {
        return new AnalysisResultRow(
            bucket: $bucket,
            metrics: array_merge(['count' => $count], $extraMetrics),
            series: $series,
        );
    }

    /**
     * @param array<string, int|float|null> $extraMetrics
     */
    public static function enrichedRow(
        string $bucketKey,
        string $bucketLabel,
        int $count,
        float $percentOfTotal = 0.0,
        float $percentOfBucket = 0.0,
        ?string $seriesKey = null,
        ?string $seriesLabel = null,
        array $extraMetrics = [],
    ): EnrichedAnalysisRow {
        $metrics = array_merge(
            [
                'count' => $count,
                'percent_of_total' => $percentOfTotal,
                'percent_of_bucket' => $percentOfBucket,
            ],
            $extraMetrics,
        );
        $formatter = new MetricValueFormatter(new MetricRegistry());

        return new EnrichedAnalysisRow(
            bucketKey: $bucketKey,
            bucketLabel: $bucketLabel,
            metrics: $metrics,
            formattedMetrics: $formatter->formatMany($metrics),
            seriesKey: $seriesKey,
            seriesLabel: $seriesLabel,
        );
    }

    /**
     * @param list<EnrichedAnalysisRow>              $rows
     * @param list<GenericAnalysisTableMetricColumn> $metricColumns
     * @param array<string, mixed>                   $chartData
     * @param list<string>                           $metricKeys
     */
    public static function normalizedResult(
        array $rows = [],
        ?string $seriesDimensionLabel = null,
        int $grandTotal = 0,
        array $metricKeys = ['count'],
        array $metricColumns = [],
        array $chartData = ['labels' => [], 'values' => []],
        ?string $visualMetricKey = null,
    ): NormalizedAnalysisResult {
        if ([] === $metricColumns) {
            $registry = new MetricRegistry();
            foreach ($metricKeys as $key) {
                $metric = $registry->get($key);
                $metricColumns[] = new GenericAnalysisTableMetricColumn(
                    key: $key,
                    label: $metric->label,
                    format: $metric->defaultFormat,
                );
            }
        }

        return new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Primary',
            seriesDimensionLabel: $seriesDimensionLabel,
            grandTotal: $grandTotal,
            rows: $rows,
            chartData: $chartData,
            metricKeys: $metricKeys,
            metricColumns: $metricColumns,
            visualMetricKey: $visualMetricKey ?? 'count',
        );
    }

    /**
     * @param list<string> $metricKeys
     */
    public static function defaultQuery(
        string $primary = 'month',
        ?string $series = null,
        array $metricKeys = [],
    ): AnalysisQuery {
        return new AnalysisQuery(
            primaryDimensionKey: $primary,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
            metricKeys: $metricKeys,
        );
    }
}
