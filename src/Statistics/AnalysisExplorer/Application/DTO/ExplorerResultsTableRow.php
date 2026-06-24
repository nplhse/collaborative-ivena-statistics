<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableRow
{
    /**
     * @param array<string, int|float>             $seriesValues
     * @param array<string, string>                $formattedSeriesValues
     * @param array<string, string>                $formattedMetricValues
     * @param array<string, int|float|string|null> $metricValues
     * @param array<string, string>                $formattedMetricPercentValues
     * @param array<string, string>                $formattedSeriesPercentValues
     * @param array<string, string>                $formattedSeriesTotalPercentValues
     */
    public function __construct(
        public string $bucketLabel,
        public array $formattedMetricValues = [],
        public array $seriesValues = [],
        public array $formattedSeriesValues = [],
        public string $formattedRowTotal = '0',
        public float $rowTotal = 0.0,
        public string $metricSubRowLabel = '',
        public array $metricValues = [],
        public array $formattedMetricPercentValues = [],
        public array $formattedSeriesPercentValues = [],
        public string $formattedRowTotalPercent = '',
        public array $formattedSeriesTotalPercentValues = [],
    ) {
    }
}
