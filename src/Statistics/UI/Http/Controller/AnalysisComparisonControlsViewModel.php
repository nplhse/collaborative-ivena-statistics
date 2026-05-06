<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class AnalysisComparisonControlsViewModel
{
    /**
     * @param array<string, array{label: string, url: string, active: bool}> $scopeChoices
     * @param array<string, array{label: string, url: string, active: bool}> $periodChoices
     */
    public function __construct(
        public bool $show,
        public array $scopeChoices,
        public string $activeScope,
        public string $activeScopeLabel,
        public array $periodChoices,
        public string $activePeriodLabel,
    ) {
    }
}
