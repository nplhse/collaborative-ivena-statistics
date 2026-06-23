<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
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
     * @param list<array{bucket: mixed, value: mixed}> $rawRows
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
        $valuesByBucket = [];

        foreach ($rawRows as $rawRow) {
            $bucket = $rawRow['bucket'] ?? null;
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $bucketKey = (string) $bucket;
            $value = $rawRow['value'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            $valuesByBucket[$bucketKey][] = (float) $value;
        }

        $rows = [];
        foreach ($valuesByBucket as $bucketKey => $values) {
            $stats = BoxPlotStats::fromDescriptiveStats(
                $this->descriptiveStatisticsCalculator->calculate($values),
            );

            $rows[] = new AnalysisResultRow(
                bucket: $bucketKey,
                bucketLabel: $this->labelResolver->labelFor($primary, $bucketKey),
                seriesKey: null,
                seriesLabel: null,
                metricValues: [
                    $query->visualMetricKey->value => $stats->median,
                ],
                boxPlot: $stats,
            );
        }

        usort(
            $rows,
            static fn (AnalysisResultRow $left, AnalysisResultRow $right): int => $left->bucket <=> $right->bucket,
        );

        return $rows;
    }
}
