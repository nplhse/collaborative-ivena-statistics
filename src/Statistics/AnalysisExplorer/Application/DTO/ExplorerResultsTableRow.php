<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableRow
{
    /**
     * @param array<string, int> $seriesValues
     */
    public function __construct(
        public string $bucketLabel,
        public int $value,
        public array $seriesValues = [],
        public int $rowTotal = 0,
    ) {
    }
}
