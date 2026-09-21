<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class AnalysisContextViewModel
{
    /**
     * @param array<string, string> $scopeGroupChoices
     * @param array<string, string> $stateChoices
     * @param array<string, string> $dispatchAreaChoices
     * @param array<string, string> $cohortChoices
     * @param array<string, string> $hospitalChoices
     * @param array<string, string> $periodChoices
     * @param array<string, string> $yearChoices
     * @param array<string, string> $quarterChoices
     * @param array<string, string> $monthChoices
     * @param array<string, string> $preservedQuery
     * @param list<string>          $formQueryKeys
     */
    public function __construct(
        public string $summary,
        public string $locationLabel,
        public string $headingScope,
        public string $headingPeriod,
        public AnalysisContextPeriodMode $periodMode,
        public string $formAction,
        public string $appliedScope,
        public string $appliedScopeGroup,
        public ?string $appliedScopeDetail,
        public string $appliedPeriod,
        public ?int $appliedYear,
        public ?int $appliedQuarter,
        public ?int $appliedMonth,
        public string $defaultScopeGroup,
        public string $defaultPeriod,
        public int $defaultYear,
        public int $defaultQuarter,
        public int $defaultMonth,
        public array $scopeGroupChoices,
        public array $stateChoices,
        public array $dispatchAreaChoices,
        public array $cohortChoices,
        public array $hospitalChoices,
        public array $periodChoices,
        public array $yearChoices,
        public array $quarterChoices,
        public array $monthChoices,
        public array $preservedQuery,
        public array $formQueryKeys,
        public bool $hidePeriod,
        public bool $monthOnlyPeriod,
    ) {
    }
}
