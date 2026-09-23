<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

final readonly class AnalysisExplorerAssistantStepViewModel
{
    public function __construct(
        public string $id,
        public string $label,
        public string $state,
    ) {
    }
}
