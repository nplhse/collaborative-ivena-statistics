<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\TimeSeries\TimeSeriesAxisFiller;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow as GenericAnalysisResultRow;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;

final readonly class ExplorerAllocationResultMapper
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private AnalysisDimensionLabelResolver $labelResolver,
        private ExplorerMetricKeyMapper $metricKeyMapper,
    ) {
    }

    /**
     * @param list<array{row: GenericAnalysisResultRow, derivedMetrics: array<string, float>}> $enriched
     *
     * @return list<AnalysisResultRow>
     */
    public function map(AnalysisResult $result, array $enriched, AnalysisQuery $query): array
    {
        $primary = $this->dimensionRegistry->get($result->primaryDimensionKey);
        $series = null !== $result->seriesDimensionKey
            ? $this->dimensionRegistry->get($result->seriesDimensionKey)
            : null;

        $this->labelResolver->warmEntityLabels($result, $primary, $series);

        $rows = [];
        foreach ($enriched as $item) {
            $row = $item['row'];
            $bucket = $row->bucket;
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $seriesValue = $row->series;
            if ($series instanceof AnalysisDimension && (null === $seriesValue || '' === $seriesValue)) {
                continue;
            }

            $bucketKey = (string) $bucket;
            $seriesKey = null;
            if (null !== $seriesValue && '' !== $seriesValue) {
                $seriesKey = (string) $seriesValue;
            }

            $metricValues = $this->buildMetricValues($row, $item['derivedMetrics'], $query->metricKeys);

            $rows[] = new AnalysisResultRow(
                bucket: $bucketKey,
                bucketLabel: $this->labelResolver->labelFor($primary, $bucket),
                seriesKey: $seriesKey,
                seriesLabel: $series instanceof AnalysisDimension && null !== $seriesKey
                    ? $this->labelResolver->labelFor($series, $seriesValue)
                    : null,
                metricValues: $metricValues,
            );
        }

        return $this->fillTemporalGaps($rows, $query, $primary);
    }

    /**
     * @param array<string, float>    $derivedMetrics
     * @param list<AnalysisMetricKey> $requestedMetrics
     *
     * @return array<string, int|float|null>
     */
    private function buildMetricValues(
        GenericAnalysisResultRow $row,
        array $derivedMetrics,
        array $requestedMetrics,
    ): array {
        $values = [];
        foreach ($requestedMetrics as $metricKey) {
            $registryKey = $this->metricKeyMapper->toRegistryKey($metricKey);
            if (isset($derivedMetrics[$registryKey])) {
                $values[$metricKey->value] = $derivedMetrics[$registryKey];
                continue;
            }

            $values[$metricKey->value] = $row->metrics[$registryKey] ?? null;
        }

        return $values;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return list<AnalysisResultRow>
     */
    private function fillTemporalGaps(array $rows, AnalysisQuery $query, AnalysisDimension $primary): array
    {
        if ([] === $rows || !$query->rowAxis->dimensionKey->isTemporalPrimary()) {
            return $rows;
        }

        if ($query->visualMetricKey->isDistributionProfile()) {
            return $rows;
        }

        $axisKeys = $this->temporalAxisKeys($query, $rows);
        if ([] === $axisKeys) {
            return $rows;
        }

        $indexed = [];
        $seriesMeta = [];
        foreach ($rows as $row) {
            $seriesKey = $row->seriesKey ?? '';
            $indexed[$row->bucket][$seriesKey] = $row;
            if (null !== $row->seriesKey) {
                $seriesMeta[$row->seriesKey] = $row->seriesLabel;
            }
        }

        $seriesKeys = [] === $seriesMeta ? [''] : array_keys($seriesMeta);
        $zeroMetrics = [];
        foreach ($query->metricKeys as $metricKey) {
            $zeroMetrics[$metricKey->value] = 0;
        }

        $filled = [];
        foreach ($axisKeys as $bucket) {
            foreach ($seriesKeys as $seriesKey) {
                if (isset($indexed[$bucket][$seriesKey])) {
                    $filled[] = $indexed[$bucket][$seriesKey];
                    continue;
                }

                $filled[] = new AnalysisResultRow(
                    bucket: $bucket,
                    bucketLabel: $this->labelResolver->labelFor($primary, is_numeric($bucket) ? (int) $bucket : $bucket),
                    seriesKey: '' === $seriesKey ? null : $seriesKey,
                    seriesLabel: '' === $seriesKey ? null : $seriesMeta[$seriesKey],
                    metricValues: $zeroMetrics,
                );
            }
        }

        return $filled;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return list<string>
     */
    private function temporalAxisKeys(AnalysisQuery $query, array $rows): array
    {
        $now = new \DateTimeImmutable('now');
        $grain = $query->rowAxis->resolvedGrain();

        return match ($grain) {
            AnalysisDimensionGrain::Day => array_column(
                TimeSeriesAxisFiller::fill(
                    TimeSeriesGrain::Day,
                    $query->periodBounds,
                    array_fill_keys(array_values(array_unique(array_map(
                        static fn (AnalysisResultRow $row): string => $row->bucket,
                        $rows,
                    ))), 1),
                    $now,
                ),
                'key',
            ),
            AnalysisDimensionGrain::Month => TimeSeriesAxisFiller::calendarMonthKeys($query->periodBounds, $now),
            default => [],
        };
    }
}
