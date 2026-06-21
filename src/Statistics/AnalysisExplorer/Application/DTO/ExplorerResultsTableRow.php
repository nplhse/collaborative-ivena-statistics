<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableRow
{
    public function __construct(
        public string $bucketLabel,
        public int $value,
    ) {
    }
}
