<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
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

        return $rows;
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
}
