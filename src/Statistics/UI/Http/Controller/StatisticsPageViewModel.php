<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class StatisticsPageViewModel
{
    /**
     * @param array<string, string>                                              $scopeUrls
     * @param array<int, string>                                                 $hospitalUrls
     * @param list<array{key: string, label: string, url: string, active: bool}> $cohortScopeChoices
     * @param array<string, string>                                              $periodUrls
     * @param list<array{id: int, name: string}>                                 $accessibleHospitals
     * @param list<array{key: string, label: string, url: string, active: bool}> $scopePrimaryMenu
     * @param list<array{label: string, url: string, active: bool}>              $scopeSecondaryMenu
     */
    public function __construct(
        public StatisticsFilter $filter,
        public array $scopeUrls,
        public array $hospitalUrls,
        public array $cohortScopeChoices,
        public ?string $cohortDropdownSelectedName,
        public array $periodUrls,
        public array $accessibleHospitals,
        public ?string $hospitalDropdownSelectedName,
        public bool $isLoggedIn,
        public string $headingScope,
        public string $headingPeriod,
        public bool $showUnscopedHint,
        public array $scopePrimaryMenu,
        public array $scopeSecondaryMenu,
        public bool $showScopeSecondaryPicker,
        public string $scopePrimaryDropdownLabel,
        public ?string $scopeSecondaryDropdownLabel,
    ) {
    }
}
