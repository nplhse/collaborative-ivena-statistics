<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

final readonly class AnalysisExplorerAssistantSummaryLineViewModel
{
    public function __construct(
        public string $label,
        public string $value,
        public string $editStep,
        public bool $showEdit,
    ) {
    }
}
