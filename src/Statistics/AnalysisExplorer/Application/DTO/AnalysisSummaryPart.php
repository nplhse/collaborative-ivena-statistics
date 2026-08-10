<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class AnalysisSummaryPart
{
    public function __construct(
        public string $text,
        public bool $emphasize = false,
    ) {
    }
}
