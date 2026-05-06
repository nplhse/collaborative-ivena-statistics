<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class StatisticsPageViewModel
{
    /**
     * @param array<string, string>              $scopeUrls
     * @param array<string, string>              $hospitalUrls
     * @param array<string, string>              $periodUrls
     * @param list<array{id: int, name: string}> $accessibleHospitals
     */
    public function __construct(
        public StatisticsFilter $filter,
        public array $scopeUrls,
        public array $hospitalUrls,
        public array $periodUrls,
        public array $accessibleHospitals,
        public ?string $hospitalDropdownSelectedName,
        public bool $isLoggedIn,
        public string $headingScope,
        public string $headingPeriod,
        public bool $showUnscopedHint,
    ) {
    }
}
