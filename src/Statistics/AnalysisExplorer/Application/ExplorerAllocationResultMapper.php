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
use App\Statistics\GenericAnalysis\Domain\ReferenceComparison;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Psr\Clock\ClockInterface;

final readonly class ExplorerAllocationResultMapper
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private AnalysisDimensionLabelResolver $labelResolver,
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private ClockInterface $clock,
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

        if (ReferenceComparison::DIMENSION_KEY === $series?->key) {
            $rows = $this->addEmptyReferenceSeries($rows, $query, $series);
        }

        $rows = $this->fillTemporalGaps($rows, $query, $primary);

        return ReferenceComparison::DIMENSION_KEY === $series?->key ? $this->withoutShareForEmptySeries($rows) : $rows;
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
     * Every group of the reference comparison is shown with every bucket (#1346): a missing combination is a bucket
     * without cases in that group, so it gets 0 cases and a share of 0 %. A group without any case loses its shares
     * afterwards (withoutShareForEmptySeries), so it is not mistaken for a group with 0 %.
     *
     * @param list<AnalysisResultRow> $rows
     *
     * @return list<AnalysisResultRow>
     */
    private function addEmptyReferenceSeries(array $rows, AnalysisQuery $query, AnalysisDimension $series): array
    {
        if ([] === $rows) {
            return $rows;
        }

        $expected = array_map(strval(...), $query->referenceHospitalIds);
        $expected[] = ReferenceComparison::OTHERS_BUCKET;

        $present = [];
        $seriesLabels = [];
        /** @var array<array-key, string> $buckets numeric bucket keys become int keys */
        $buckets = [];
        foreach ($rows as $row) {
            $present[$row->bucket][(string) $row->seriesKey] = true;
            $seriesLabels[(string) $row->seriesKey] = $row->seriesLabel;
            $buckets[$row->bucket] = $row->bucketLabel;
        }

        $missingSeries = array_values(array_diff($expected, array_keys($seriesLabels)));
        $this->labelResolver->warmAdditionalEntityIds(
            $series->key,
            array_values(array_map(intval(...), array_filter($missingSeries, is_numeric(...)))),
        );
        foreach ($missingSeries as $seriesKey) {
            $seriesLabels[$seriesKey] = $this->labelResolver->labelFor($series, is_numeric($seriesKey) ? (int) $seriesKey : $seriesKey);
        }

        $emptyMetrics = [];
        foreach ($query->metricKeys as $metricKey) {
            $emptyMetrics[$metricKey->value] = match ($metricKey) {
                AnalysisMetricKey::AllocationCount => 0,
                AnalysisMetricKey::PercentOfSeries => 0.0,
                default => null,
            };
        }

        foreach ($buckets as $bucket => $bucketLabel) {
            foreach ($expected as $seriesKey) {
                if (isset($present[$bucket][$seriesKey])) {
                    continue;
                }

                $rows[] = new AnalysisResultRow(
                    bucket: (string) $bucket,
                    bucketLabel: $bucketLabel,
                    seriesKey: $seriesKey,
                    seriesLabel: $seriesLabels[$seriesKey] ?? $seriesKey,
                    metricValues: $emptyMetrics,
                );
            }
        }

        // Keep the bucket order of the query and, within a bucket, the order of the groups.
        $bucketOrder = array_flip(array_map(strval(...), array_keys($buckets)));
        $seriesOrder = array_flip($expected);
        usort($rows, static fn (AnalysisResultRow $a, AnalysisResultRow $b): int => [$bucketOrder[$a->bucket] ?? \PHP_INT_MAX, $seriesOrder[(string) $a->seriesKey] ?? \PHP_INT_MAX]
            <=> [$bucketOrder[$b->bucket] ?? \PHP_INT_MAX, $seriesOrder[(string) $b->seriesKey] ?? \PHP_INT_MAX]);

        return $rows;
    }

    /**
     * A series without cases has no defined share within the series (#1346), also where gaps were filled with 0.
     *
     * @param list<AnalysisResultRow> $rows
     *
     * @return list<AnalysisResultRow>
     */
    private function withoutShareForEmptySeries(array $rows): array
    {
        $shareKey = AnalysisMetricKey::PercentOfSeries->value;
        $countKey = AnalysisMetricKey::AllocationCount->value;

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->seriesKey] = ($totals[(string) $row->seriesKey] ?? 0) + (int) ($row->metricValues[$countKey] ?? 0);
        }

        return array_map(static function (AnalysisResultRow $row) use ($totals, $shareKey): AnalysisResultRow {
            if (!\array_key_exists($shareKey, $row->metricValues) || 0 !== ($totals[(string) $row->seriesKey] ?? 0)) {
                return $row;
            }

            return new AnalysisResultRow(
                bucket: $row->bucket,
                bucketLabel: $row->bucketLabel,
                seriesKey: $row->seriesKey,
                seriesLabel: $row->seriesLabel,
                metricValues: [$shareKey => null] + $row->metricValues,
            );
        }, $rows);
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

        $seriesKeys = [] === $seriesMeta ? [''] : array_map(strval(...), array_keys($seriesMeta));
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
        $now = $this->clock->now();
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
