<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;

final readonly class HospitalsDistributionResultMapper
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private AnalysisDimensionLabelResolver $labelResolver,
        private DescriptiveStatisticsCalculator $descriptiveStatisticsCalculator,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    /**
     * @param list<array{bucket: mixed, series?: mixed, value: mixed}> $rawRows
     *
     * @return list<AnalysisResultRow>
     */
    public function map(array $rawRows, GenericAnalysisQuery $gaQuery, AnalysisQuery $query): array
    {
        $profile = $this->profileRegistry->profileFor($query->visualMetricKey);
        if (!$profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return [];
        }

        $primary = $this->dimensionRegistry->get($gaQuery->primaryDimensionKey);
        $series = null !== $gaQuery->seriesDimensionKey
            ? $this->dimensionRegistry->get($gaQuery->seriesDimensionKey)
            : null;

        $this->labelResolver->warmDistributionEntityLabels($rawRows, $primary, $series);

        /** @var array<string, list<float>> $valuesByCell keyed by "bucket|series" */
        $valuesByCell = [];

        foreach ($rawRows as $rawRow) {
            $bucket = $rawRow['bucket'] ?? null;
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $bucketKey = (string) $bucket;
            $seriesValue = $rawRow['series'] ?? null;
            if ($series instanceof AnalysisDimension && (null === $seriesValue || '' === $seriesValue)) {
                continue;
            }

            $seriesKey = null !== $seriesValue && '' !== $seriesValue ? (string) $seriesValue : '';
            $value = $rawRow['value'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            $valuesByCell[$bucketKey.'|'.$seriesKey][] = (float) $value;
        }

        $rows = [];
        foreach ($valuesByCell as $cellKey => $values) {
            $separatorPosition = strpos($cellKey, '|');
            $bucketKey = false === $separatorPosition ? $cellKey : substr($cellKey, 0, $separatorPosition);
            $seriesKey = false === $separatorPosition ? '' : substr($cellKey, $separatorPosition + 1);
            $stats = BoxPlotStats::fromDescriptiveStats(
                $this->descriptiveStatisticsCalculator->calculate($values),
            );

            $resolvedSeriesKey = '' === $seriesKey ? null : $seriesKey;

            $rows[] = new AnalysisResultRow(
                bucket: $bucketKey,
                bucketLabel: $this->labelResolver->labelFor($primary, $bucketKey),
                seriesKey: $resolvedSeriesKey,
                seriesLabel: $series instanceof AnalysisDimension && null !== $resolvedSeriesKey
                    ? $this->labelResolver->labelFor($series, $resolvedSeriesKey)
                    : null,
                metricValues: [
                    $query->visualMetricKey->value => $stats->median,
                ],
                boxPlot: $stats,
            );
        }

        usort(
            $rows,
            static function (AnalysisResultRow $left, AnalysisResultRow $right): int {
                $bucketCompare = $left->bucket <=> $right->bucket;
                if (0 !== $bucketCompare) {
                    return $bucketCompare;
                }

                return ($left->seriesKey ?? '') <=> ($right->seriesKey ?? '');
            },
        );

        return $rows;
    }
}
