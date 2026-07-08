<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLabelResolver;
use App\Statistics\AnalysisExplorer\UI\Http\Navigation\ExplorerLibraryQueryKeys;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisExplorerLibraryPageViewModelFactory
{
    private const string TAB_ALL = 'all';

    private const string TAB_FAVORITES = 'favorites';

    private const string TAB_MY_VIEWS = 'my_views';

    public function __construct(
        private SavedExplorerViewRepository $repository,
        private SavedExplorerViewFavoriteService $favoriteService,
        private SavedExplorerViewLabelResolver $labelResolver,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request, ?User $user): AnalysisExplorerLibraryPageViewModel
    {
        $isLoggedIn = $user instanceof User;
        $activeTab = $this->resolveActiveTab($request, $isLoggedIn);
        $activeCategory = self::TAB_ALL === $activeTab
            ? $this->normalizeCategoryFilter($request->query->getString(ExplorerLibraryQueryKeys::CATEGORY))
            : null;
        $searchQuery = $this->normalizeSearchQuery($request->query->getString(ExplorerLibraryQueryKeys::SEARCH));

        return new AnalysisExplorerLibraryPageViewModel(
            activeTab: $activeTab,
            activeCategory: $activeCategory,
            tabs: $this->tabs($request, $activeTab, $isLoggedIn, $user),
            categoryFilters: self::TAB_ALL === $activeTab
                ? $this->categoryFilters($request, $activeCategory)
                : [],
            cards: $this->cardsForTab($request, $user, $activeTab, $activeCategory, $searchQuery),
            isLoggedIn: $isLoggedIn,
            searchQuery: $searchQuery ?? '',
        );
    }

    private function normalizeSearchQuery(string $search): ?string
    {
        $search = trim($search);

        return '' === $search ? null : $search;
    }

    private function resolveActiveTab(Request $request, bool $isLoggedIn): string
    {
        $tab = $request->query->getString(ExplorerLibraryQueryKeys::TAB, self::TAB_ALL);

        if (!\in_array($tab, [self::TAB_ALL, self::TAB_FAVORITES, self::TAB_MY_VIEWS], true)) {
            return self::TAB_ALL;
        }

        if (!$isLoggedIn && \in_array($tab, [self::TAB_FAVORITES, self::TAB_MY_VIEWS], true)) {
            return self::TAB_ALL;
        }

        return $tab;
    }

    private function normalizeCategoryFilter(string $category): ?string
    {
        $category = trim($category);

        return '' === $category ? null : $category;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardsForTab(
        Request $request,
        ?User $user,
        string $activeTab,
        ?string $activeCategory,
        ?string $searchQuery,
    ): array {
        $views = match ($activeTab) {
            self::TAB_FAVORITES => $user instanceof User
                ? $this->favoriteService->listViewsForUser($user)
                : [],
            self::TAB_MY_VIEWS => $user instanceof User
                ? $this->repository->findByCreatorOrdered($user)
                : [],
            default => $this->systemViewsForCategory($activeCategory),
        };

        $cards = [];
        foreach ($views as $view) {
            if (!$this->matchesSearch($view, $searchQuery)) {
                continue;
            }

            $cards[] = $this->buildCard($request, $view, $user);
        }

        return $this->sortCardsAlphabetically($cards);
    }

    /**
     * @return list<SavedExplorerView>
     */
    private function systemViewsForCategory(?string $activeCategory): array
    {
        if (null === $activeCategory) {
            return $this->repository->findAllSystemViewsOrdered();
        }

        $views = [];
        foreach ($this->repository->findAllSystemViewsOrdered() as $view) {
            if ($this->categoryKey($view->getCategory()) === $activeCategory) {
                $views[] = $view;
            }
        }

        return $views;
    }

    private function matchesSearch(SavedExplorerView $view, ?string $searchQuery): bool
    {
        if (null === $searchQuery) {
            return true;
        }

        $needle = mb_strtolower($searchQuery);
        $haystack = mb_strtolower(trim(
            $this->labelResolver->title($view)."\n".($this->labelResolver->description($view) ?? ''),
        ));

        return str_contains($haystack, $needle);
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function sortCardsAlphabetically(array $cards): array
    {
        usort(
            $cards,
            static fn (array $left, array $right): int => strcasecmp((string) $left['title'], (string) $right['title']),
        );

        return $cards;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function distinctSystemCategories(): array
    {
        $categories = [];
        foreach ($this->repository->findAllSystemViewsOrdered() as $view) {
            $key = $this->categoryKey($view->getCategory());
            if (!isset($categories[$key])) {
                $categories[$key] = [
                    'key' => $key,
                    'label' => $this->categoryLabel($view->getCategory()),
                ];
            }
        }

        $categories = array_values($categories);
        usort(
            $categories,
            static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']),
        );

        return $categories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tabs(Request $request, string $activeTab, bool $isLoggedIn, ?User $user): array
    {
        $definitions = [
            self::TAB_ALL => 'stats.analysis_explorer.library.tab.overview',
        ];
        if ($isLoggedIn) {
            $definitions[self::TAB_FAVORITES] = 'stats.analysis_explorer.library.tab.favorites';
            $definitions[self::TAB_MY_VIEWS] = 'stats.analysis_explorer.library.tab.my_views';
        }

        $tabs = [];
        foreach ($definitions as $key => $labelKey) {
            $count = $this->tabCount($key, $user);
            $tabs[] = [
                'key' => $key,
                'label' => $this->translator->trans($labelKey, [], 'statistics'),
                'active' => $key === $activeTab,
                'url' => $this->router->generate('app_stats_analysis_library', $this->tabQuery($request, $key)),
                ...null !== $count ? ['count' => $count] : [],
            ];
        }

        return $tabs;
    }

    private function tabCount(string $tabKey, ?User $user): ?int
    {
        if (!$user instanceof User) {
            return null;
        }

        return match ($tabKey) {
            self::TAB_FAVORITES => \count($this->favoriteService->listViewsForUser($user)),
            self::TAB_MY_VIEWS => \count($this->repository->findByCreatorOrdered($user)),
            default => null,
        };
    }

    /**
     * @return list<array{key: string, label: string, active: bool, url: string}>
     */
    private function categoryFilters(Request $request, ?string $activeCategory): array
    {
        $filters = [[
            'key' => '',
            'label' => $this->translator->trans('stats.analysis_explorer.library.category.all', [], 'statistics'),
            'active' => null === $activeCategory,
            'url' => $this->router->generate('app_stats_analysis_library', $this->allTabQuery($request)),
        ]];

        foreach ($this->distinctSystemCategories() as $category) {
            $filters[] = [
                'key' => $category['key'],
                'label' => $category['label'],
                'active' => $category['key'] === $activeCategory,
                'url' => $this->router->generate('app_stats_analysis_library', $this->allTabQuery(
                    $request,
                    [ExplorerLibraryQueryKeys::CATEGORY => $category['key']],
                )),
            ];
        }

        return $filters;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function tabQuery(Request $request, string $tab, array $extra = []): array
    {
        return array_merge(
            $this->libraryQuery($request),
            [ExplorerLibraryQueryKeys::TAB => $tab],
            $extra,
        );
    }

    /**
     * @return array<string, string>
     */
    private function libraryQuery(Request $request): array
    {
        $query = $this->scopeQuery($request);
        $search = $this->normalizeSearchQuery($request->query->getString(ExplorerLibraryQueryKeys::SEARCH));
        if (null !== $search) {
            $query[ExplorerLibraryQueryKeys::SEARCH] = $search;
        }

        return $query;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function allTabQuery(Request $request, array $extra = []): array
    {
        return $this->tabQuery($request, self::TAB_ALL, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCard(Request $request, SavedExplorerView $view, ?User $user): array
    {
        $config = $view->getConfigJson();
        $query = $config['query'] ?? [];
        $presentation = $config['presentation'] ?? [];
        $rowAxis = \is_array($query['rows'] ?? null) ? $query['rows'] : [];
        $dimension = (string) ($rowAxis['dimension'] ?? $query['dimension'] ?? '');
        $grain = (string) ($rowAxis['grain'] ?? $query['grain'] ?? '');
        $viewId = $view->getId();
        $canFavorite = $user instanceof User && null !== $viewId;
        $categoryKey = $this->categoryKey($view->getCategory());
        $categoryLabel = $view->isSystem() ? $this->categoryLabel($view->getCategory()) : '';

        return [
            'id' => $viewId,
            'title' => $this->labelResolver->title($view),
            'description' => $this->labelResolver->description($view) ?? '',
            'dimension' => $this->dimensionLabel($dimension),
            'grain' => $this->grainLabel($grain),
            'chartType' => $this->chartTypeLabel((string) ($presentation['chartType'] ?? '')),
            'isSystem' => $view->isSystem(),
            'viewTypeLabel' => $view->isSystem()
                ? $this->translator->trans('stats.analysis_explorer.view_type.system', [], 'statistics')
                : $this->translator->trans('stats.analysis_explorer.view_type.user', [], 'statistics'),
            'categoryKey' => $categoryKey,
            'categoryLabel' => $categoryLabel,
            'categoryUrl' => $view->isSystem()
                ? $this->router->generate('app_stats_analysis_library', $this->allTabQuery(
                    $request,
                    [ExplorerLibraryQueryKeys::CATEGORY => $categoryKey],
                ))
                : null,
            'openUrl' => null !== $viewId
                ? $this->router->generate('app_stats_analysis_explorer_view', array_merge(
                    ['view' => (string) $viewId],
                    $this->scopeQuery($request),
                ))
                : '#',
            'canFavorite' => $canFavorite,
            'isFavorite' => $canFavorite && $this->favoriteService->isFavorite($user, $view),
            'favoriteUrl' => $canFavorite
                ? $this->router->generate('app_stats_analysis_explorer_favorite_toggle', ['id' => $viewId])
                : null,
            'favoriteToken' => $canFavorite ? 'explorer_favorite_'.$viewId : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scopeQuery(Request $request): array
    {
        $query = [];
        foreach ([
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
            StatisticsQueryKeys::PERIOD,
            StatisticsQueryKeys::YEAR,
            StatisticsQueryKeys::MONTH,
            StatisticsQueryKeys::QUARTER,
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }

    private function categoryKey(string $category): string
    {
        if ('My views' === $category) {
            return 'my_views';
        }

        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '_', $category) ?? $category);
    }

    private function categoryLabel(string $category): string
    {
        $key = $this->categoryKey($category);

        return $this->translator->trans('stats.analysis_explorer.library.category.'.$key, [], 'statistics');
    }

    private function dimensionLabel(string $dimension): string
    {
        if ('' === $dimension) {
            return '';
        }

        $key = 'stats.analysis_explorer.dimension.'.$dimension;

        return $this->translator->trans($key, [], 'statistics');
    }

    private function grainLabel(string $grain): string
    {
        if ('' === $grain) {
            return '';
        }

        return match ($grain) {
            'month' => $this->translator->trans('stats.analysis_explorer.dimension.month', [], 'statistics'),
            'year' => $this->translator->trans('stats.analysis_explorer.dimension.year', [], 'statistics'),
            'total' => $this->translator->trans('stats.analysis_explorer.grain.total', [], 'statistics'),
            default => $grain,
        };
    }

    private function chartTypeLabel(string $chartType): string
    {
        if ('' === $chartType) {
            return '';
        }

        $key = 'stats.analysis_explorer.chart.'.$chartType;

        return $this->translator->trans($key, [], 'statistics');
    }
}
