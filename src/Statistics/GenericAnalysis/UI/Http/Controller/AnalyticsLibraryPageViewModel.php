<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

final readonly class AnalyticsLibraryPageViewModel
{
    /**
     * @param list<array{key: string, label: string, active: bool, url: string}> $tabs
     * @param list<array{key: string, label: string}>                            $categories
     * @param list<array<string, mixed>>                                         $recommendedCards
     * @param list<array<string, mixed>>                                         $favoriteCards
     * @param list<array<string, mixed>>                                         $recentCards
     * @param list<array<string, mixed>>                                         $frequentCards
     * @param list<array<string, mixed>>                                         $categoryCards
     * @param list<array{key: string, label: string, active: bool, url: string}> $categoryFilters
     */
    public function __construct(
        public string $searchQuery,
        public string $activeTab,
        public ?string $activeCategory,
        public array $tabs,
        public array $categories,
        public array $recommendedCards,
        public array $favoriteCards,
        public array $recentCards,
        public array $frequentCards,
        public array $categoryCards,
        public array $categoryFilters,
        public string $builderUrl,
        public bool $isLoggedIn,
    ) {
    }
}
