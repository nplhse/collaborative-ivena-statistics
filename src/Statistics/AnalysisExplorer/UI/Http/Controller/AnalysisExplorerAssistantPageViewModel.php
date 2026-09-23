<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

final readonly class AnalysisExplorerAssistantPageViewModel
{
    /**
     * @param list<AnalysisExplorerAssistantStepViewModel>        $steps
     * @param list<AnalysisExplorerAssistantSummaryLineViewModel> $summaryLines
     */
    public function __construct(
        public string $step,
        public ?string $goal,
        public string $libraryUrl,
        public string $formAction,
        public string $cancelUrl,
        public array $steps,
        public string $stepDescription,
        public ?string $goalTitle,
        public ?string $summaryTitle,
        public ?string $summaryPresentation,
        public array $summaryLines = [],
    ) {
    }
}
