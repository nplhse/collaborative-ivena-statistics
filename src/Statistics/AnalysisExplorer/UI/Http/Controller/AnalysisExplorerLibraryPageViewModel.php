<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

final readonly class AnalysisExplorerLibraryPageViewModel
{
    /**
     * @param list<array<string, mixed>>                                         $tabs
     * @param list<array{key: string, label: string, active: bool, url: string}> $categoryFilters
     * @param list<array<string, mixed>>                                         $cards
     */
    public function __construct(
        public string $activeTab,
        public ?string $activeCategory,
        public array $tabs,
        public array $categoryFilters,
        public array $cards,
        public bool $isLoggedIn,
        public string $searchQuery = '',
        public string $origin = 'all',
        public string $userQuery = '',
        /** @var list<array{key: string, label: string, active: bool, url: string}> */
        public array $originFilters = [],
        public ?string $activeDimension = null,
        public ?string $activeChart = null,
        public ?string $activeGrain = null,
        /** @var list<array{key: string, label: string, active: bool, url: string}> */
        public array $dimensionFilters = [],
        /** @var list<array{key: string, label: string, active: bool, url: string}> */
        public array $chartFilters = [],
        /** @var list<array{key: string, label: string, active: bool, url: string}> */
        public array $grainFilters = [],
        public bool $hasActiveFilters = false,
        public string $resetUrl = '',
        /** @var list<array{label: string, value: string}> */
        public array $activeFilterBadges = [],
        public int $currentPage = 1,
        public int $lastPage = 1,
        public bool $hasToPaginate = false,
        public int $resultFrom = 0,
        public int $resultTo = 0,
        public int $resultTotal = 0,
    ) {
    }
}
