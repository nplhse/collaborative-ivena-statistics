<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableMetricColumn;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResultNormalizer
{
    private const int MAX_FIXED_BUCKET_FILL = 60;

    /** @var array<string, array<int, string>> */
    private array $entityLabelsByDimension = [];

    public function __construct(
        private readonly DimensionRegistry $dimensionRegistry,
        private readonly MetricRegistry $metricRegistry,
        private readonly MetricValueFormatter $metricValueFormatter,
        private readonly TranslatorInterface $translator,
        private readonly GenericAnalysisEntityLabelResolverInterface $entityLabelResolver,
        private readonly HospitalCohortLabelResolver $hospitalCohortLabelResolver,
    ) {
    }

    /**
     * @param list<array{row: AnalysisResultRow, derivedMetrics: array<string, float>}> $enrichedRows
     */
    public function normalize(
        AnalysisResult $result,
        string $title,
        array $enrichedRows,
        AnalysisQuery $query,
    ): NormalizedAnalysisResult {
        $primary = $this->dimensionRegistry->get($result->primaryDimensionKey);
        $series = null !== $result->seriesDimensionKey
            ? $this->dimensionRegistry->get($result->seriesDimensionKey)
            : null;

        $this->entityLabelsByDimension = $this->resolveEntityLabels($result, $primary, $series);
        $metricKeys = $result->metricKeys;
        $metricColumns = $this->buildMetricColumns($metricKeys);
        $visualMetricKey = $query->resolvedVisualMetricKey();

        $indexed = [];
        foreach ($enrichedRows as $item) {
            $row = $item['row'];
            $bucketKey = $this->bucketKey($row->bucket);
            $seriesKey = null !== $row->series ? $this->bucketKey($row->series) : null;
            $indexed[$this->compositeKey($bucketKey, $seriesKey)] = $item;
        }

        $bucketKeys = $this->orderedBucketKeys($primary, $indexed, $result->includeNullBuckets);
        $seriesKeys = $series instanceof AnalysisDimension
            ? $this->orderedSeriesKeys($series, $indexed, $result->includeNullBuckets)
            : [null];

        $normalizedRows = [];
        foreach ($bucketKeys as $bucketKey) {
            foreach ($seriesKeys as $seriesKey) {
                $item = $indexed[$this->compositeKey($bucketKey, $seriesKey)] ?? null;
                $metrics = $this->buildRowMetrics($item, $metricKeys);
                $normalizedRows[] = new EnrichedAnalysisRow(
                    bucketKey: $this->bucketKey($bucketKey),
                    bucketLabel: $this->labelFor($primary, $this->bucketKey($bucketKey)),
                    metrics: $metrics,
                    formattedMetrics: $this->metricValueFormatter->formatMany($metrics),
                    seriesKey: null !== $seriesKey ? $this->bucketKey($seriesKey) : null,
                    seriesLabel: $series instanceof AnalysisDimension && null !== $seriesKey
                        ? $this->labelFor($series, $this->bucketKey($seriesKey))
                        : null,
                );
            }
        }

        $hasSeries = $series instanceof AnalysisDimension;

        return new NormalizedAnalysisResult(
            title: $title,
            primaryDimensionLabel: $primary->label,
            seriesDimensionLabel: $series?->label,
            grandTotal: $result->grandTotal,
            rows: $normalizedRows,
            chartData: $this->buildChartData($primary, $normalizedRows, $hasSeries, $visualMetricKey),
            metricKeys: $metricKeys,
            metricColumns: $metricColumns,
            visualMetricKey: $visualMetricKey,
            recommendedChartType: $primary->recommendedChartType,
        );
    }

    /**
     * @param list<string> $metricKeys
     *
     * @return list<GenericAnalysisTableMetricColumn>
     */
    private function buildMetricColumns(array $metricKeys): array
    {
        $columns = [];
        foreach ($metricKeys as $key) {
            $metric = $this->metricRegistry->get($key);
            $columns[] = new GenericAnalysisTableMetricColumn(
                key: $key,
                label: $metric->label,
                format: $metric->defaultFormat,
            );
        }

        usort(
            $columns,
            fn (GenericAnalysisTableMetricColumn $a, GenericAnalysisTableMetricColumn $b): int => $this->metricRegistry->get($a->key)->sortPriority
                <=> $this->metricRegistry->get($b->key)->sortPriority,
        );

        return $columns;
    }

    /**
     * @param list<string>                                                             $metricKeys
     * @param array{row: AnalysisResultRow, derivedMetrics: array<string, float>}|null $item
     *
     * @return array<string, int|float|null>
     */
    private function buildRowMetrics(?array $item, array $metricKeys): array
    {
        $metrics = [];
        foreach ($metricKeys as $key) {
            if (null === $item) {
                $metrics[$key] = 'count' === $key ? 0 : null;

                continue;
            }

            if (isset($item['derivedMetrics'][$key])) {
                $metrics[$key] = $item['derivedMetrics'][$key];

                continue;
            }

            $metrics[$key] = $item['row']->metrics[$key] ?? ('count' === $key ? 0 : null);
        }

        return $metrics;
    }

    /**
     * @param array<string, array{row: AnalysisResultRow, derivedMetrics: array<string, float>}> $indexed
     *
     * @return list<string>
     */
    private function orderedBucketKeys(AnalysisDimension $dimension, array $indexed, bool $includeNullBuckets): array
    {
        $fromData = [];
        foreach (array_keys($indexed) as $key) {
            $bucketKey = explode('|', $key, 2)[0];
            if ($this->isExcludedNullBucketKey($dimension, $bucketKey, $includeNullBuckets)) {
                continue;
            }
            $fromData[$bucketKey] = true;
        }

        if ([] !== $dimension->fixedBuckets && \count($dimension->fixedBuckets) <= self::MAX_FIXED_BUCKET_FILL) {
            $keys = $this->normalizeKeyList($dimension->fixedBuckets);
            $keys = $this->filterNullBucketKeys($dimension, $keys, $includeNullBuckets);

            foreach (array_keys($fromData) as $extra) {
                if (!\in_array($extra, $keys, true)) {
                    $keys[] = $extra;
                }
            }

            return $this->orderKeys($keys, $dimension);
        }

        return $this->orderKeys(array_keys($fromData), $dimension);
    }

    /**
     * @param array<string, array{row: AnalysisResultRow, derivedMetrics: array<string, float>}> $indexed
     *
     * @return list<string|null>
     */
    private function orderedSeriesKeys(AnalysisDimension $dimension, array $indexed, bool $includeNullBuckets): array
    {
        $fromData = [];
        foreach (array_keys($indexed) as $key) {
            $parts = explode('|', $key, 2);
            $seriesKey = $parts[1] ?? '';
            if ($this->isExcludedNullBucketKey($dimension, $seriesKey, $includeNullBuckets)) {
                continue;
            }
            $fromData[$seriesKey] = true;
        }

        if ([] !== $dimension->fixedBuckets && \count($dimension->fixedBuckets) <= self::MAX_FIXED_BUCKET_FILL) {
            $keys = $this->filterNullBucketKeys(
                $dimension,
                $this->normalizeKeyList($dimension->fixedBuckets),
                $includeNullBuckets,
            );
        } else {
            $keys = array_keys($fromData);
        }

        $sorted = $this->sortKeys($keys, $dimension);

        return array_map(
            static fn (string $key): ?string => '' === $key ? null : $key,
            $sorted,
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function filterNullBucketKeys(AnalysisDimension $dimension, array $keys, bool $includeNullBuckets): array
    {
        if ($includeNullBuckets) {
            return $keys;
        }

        return array_values(array_filter(
            $keys,
            fn (string $key): bool => !$this->isExcludedNullBucketKey($dimension, $key, false),
        ));
    }

    private function isExcludedNullBucketKey(
        AnalysisDimension $dimension,
        string $bucketKey,
        bool $includeNullBuckets,
    ): bool {
        if ($includeNullBuckets) {
            return false;
        }

        return '__null__' === $bucketKey
            || \in_array($bucketKey, $dimension->nullBucketKeys, true);
    }

    /**
     * @param list<int|string|float|bool|null> $rawKeys
     *
     * @return list<string>
     */
    private function normalizeKeyList(array $rawKeys): array
    {
        return array_map($this->bucketKey(...), $rawKeys);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function orderKeys(array $keys, AnalysisDimension $dimension): array
    {
        $sorted = $this->sortKeys($keys, $dimension);

        return $dimension->sortAscending ? $sorted : array_reverse($sorted);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sortKeys(array $keys, AnalysisDimension $dimension): array
    {
        $keys = array_values(array_unique($keys));
        $fixedBucketKeys = $this->normalizeKeyList($dimension->fixedBuckets);

        usort($keys, static function (string $a, string $b) use ($fixedBucketKeys): int {
            if ([] !== $fixedBucketKeys) {
                $posA = array_search($a, $fixedBucketKeys, true);
                $posB = array_search($b, $fixedBucketKeys, true);
                if (false !== $posA && false !== $posB) {
                    return $posA <=> $posB;
                }
            }

            if (is_numeric($a) && is_numeric($b)) {
                return (float) $a <=> (float) $b;
            }

            return strcmp($a, $b);
        });

        return $keys;
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     *
     * @return array<string, mixed>
     */
    private function buildChartData(
        AnalysisDimension $primary,
        array $rows,
        bool $hasSeries,
        string $visualMetricKey,
    ): array {
        if (!$hasSeries) {
            return [
                'type' => $primary->recommendedChartType,
                'labels' => array_map(static fn (EnrichedAnalysisRow $r): string => $r->bucketLabel, $rows),
                'values' => array_map(
                    fn (EnrichedAnalysisRow $r): int|float => $this->visualMetricValue($r, $visualMetricKey),
                    $rows,
                ),
                'visualMetricKey' => $visualMetricKey,
            ];
        }

        $labels = [];
        $seriesMap = [];
        foreach ($rows as $row) {
            if (!\in_array($row->bucketLabel, $labels, true)) {
                $labels[] = $row->bucketLabel;
            }
            $seriesName = $row->seriesLabel ?? '—';
            $seriesMap[$seriesName] ??= [];
            $seriesMap[$seriesName][$row->bucketLabel] = $this->visualMetricValue($row, $visualMetricKey);
        }

        $series = [];
        foreach ($seriesMap as $name => $byLabel) {
            $data = [];
            foreach ($labels as $label) {
                $data[] = $byLabel[$label] ?? 0;
            }
            $series[] = ['name' => $name, 'data' => $data];
        }

        return [
            'type' => $primary->recommendedChartType,
            'labels' => $labels,
            'series' => $series,
            'visualMetricKey' => $visualMetricKey,
        ];
    }

    private function visualMetricValue(EnrichedAnalysisRow $row, string $visualMetricKey): int|float
    {
        $value = $row->metrics[$visualMetricKey] ?? null;

        return $value ?? 0;
    }

    private function compositeKey(int|string|float|bool|null $bucketKey, int|string|float|bool|null $seriesKey): string
    {
        return $this->bucketKey($bucketKey).'|'.(null === $seriesKey ? '' : $this->bucketKey($seriesKey));
    }

    private function bucketKey(int|string|float|bool|null $bucket): string
    {
        if (null === $bucket) {
            return '__null__';
        }

        if (\is_bool($bucket)) {
            return $bucket ? '1' : '0';
        }

        return (string) $bucket;
    }

    private function labelFor(AnalysisDimension $dimension, string $bucketKey): string
    {
        if ('__null__' === $bucketKey) {
            return 'Unknown';
        }

        if ('hospital_cohort' === $dimension->key) {
            $cohortKey = HospitalCohortKey::tryFrom($bucketKey);
            if ($cohortKey instanceof HospitalCohortKey) {
                return $this->hospitalCohortLabelResolver->label($cohortKey);
            }
        }

        $lookupKey = is_numeric($bucketKey) ? (int) $bucketKey : $bucketKey;
        if (isset($dimension->valueLabelTranslationKeys[$lookupKey])) {
            return $this->translator->trans($dimension->valueLabelTranslationKeys[$lookupKey]);
        }
        if (isset($dimension->valueLabelTranslationKeys[$bucketKey])) {
            return $this->translator->trans($dimension->valueLabelTranslationKeys[$bucketKey]);
        }
        if (isset($dimension->valueLabels[$lookupKey])) {
            return $dimension->valueLabels[$lookupKey];
        }
        if (isset($dimension->valueLabels[$bucketKey])) {
            return $dimension->valueLabels[$bucketKey];
        }

        if (AnalysisDimensionType::Boolean === $dimension->type) {
            return match ($bucketKey) {
                '1', 'true' => 'Yes',
                '0', 'false' => 'No',
                default => $bucketKey,
            };
        }

        if (is_numeric($bucketKey) && isset($this->entityLabelsByDimension[$dimension->key])) {
            $entityId = (int) $bucketKey;

            return $this->entityLabelsByDimension[$dimension->key][$entityId] ?? $bucketKey;
        }

        if ('month' === $dimension->key && is_numeric($bucketKey)) {
            $month = max(1, min(12, (int) $bucketKey));
            $formatted = \IntlDateFormatter::formatObject(
                new \DateTimeImmutable(sprintf('2024-%02d-01', $month)),
                'MMM',
                'en',
            );

            return false !== $formatted && '' !== $formatted ? $formatted : (string) $month;
        }

        return $bucketKey;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function resolveEntityLabels(
        AnalysisResult $result,
        AnalysisDimension $primary,
        ?AnalysisDimension $series,
    ): array {
        /** @var array<string, list<int>> $idsByDimension */
        $idsByDimension = [];

        foreach ($result->rows as $row) {
            $this->collectEntityId($idsByDimension, $primary->key, $row->bucket);
            if ($series instanceof AnalysisDimension) {
                $this->collectEntityId($idsByDimension, $series->key, $row->series);
            }
        }

        $labels = [];
        foreach ($idsByDimension as $dimensionKey => $ids) {
            $labels[$dimensionKey] = $this->entityLabelResolver->resolve($dimensionKey, $ids);
        }

        return $labels;
    }

    /**
     * @param array<string, list<int>> $idsByDimension
     */
    private function collectEntityId(array &$idsByDimension, string $dimensionKey, mixed $value): void
    {
        if (!$this->entityLabelResolver->supports($dimensionKey) || !is_numeric($value)) {
            return;
        }

        $idsByDimension[$dimensionKey] ??= [];
        $idsByDimension[$dimensionKey][] = (int) $value;
    }
}
