<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use Symfony\UX\Pagination\NumberedPaginationInterface;

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
        /** @var NumberedPaginationInterface<mixed> */
        public NumberedPaginationInterface $pagination,
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
        public string $assistantUrl = '',
    ) {
    }

    /**
     * @return \Closure(array<string, mixed>): array<string, string>
     */
    public function getPaginationLinkAttributes(): \Closure
    {
        return static function (array $link): array {
            $relation = $link['relation'] ?? '';
            $page = $link['page'] ?? '';

            $testId = match ($relation) {
                'previous' => 'stats-analysis-explorer-library-page-previous',
                'next' => 'stats-analysis-explorer-library-page-next',
                default => 'stats-analysis-explorer-library-page-'.$page,
            };

            return ['data-testid' => $testId];
        };
    }
}
