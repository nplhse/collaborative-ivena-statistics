<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableMetricColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public string $footerLabel = '',
    ) {
    }
}
