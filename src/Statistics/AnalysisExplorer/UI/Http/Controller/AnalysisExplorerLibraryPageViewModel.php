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
    ) {
    }
}
