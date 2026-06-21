<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableRow
{
    /**
     * @param array<string, int|float> $seriesValues
     * @param array<string, string>    $formattedMetricValues
     */
    public function __construct(
        public string $bucketLabel,
        public array $formattedMetricValues = [],
        public array $seriesValues = [],
        public string $formattedRowTotal = '0',
        public float $rowTotal = 0.0,
    ) {
    }
}
