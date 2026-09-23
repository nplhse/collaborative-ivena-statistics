<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\ExplorerViewActivityPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerViewAuthorPresenter;
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

    private const string ORIGIN_ALL = 'all';

    private const string ORIGIN_SYSTEM = 'system';

    private const string ORIGIN_PUBLIC = 'public';

    private const int PAGE_SIZE = 10;

    public function __construct(
        private SavedExplorerViewRepository $repository,
        private SavedExplorerViewFavoriteService $favoriteService,
        private SavedExplorerViewLabelResolver $labelResolver,
        private ExplorerViewAuthorPresenter $authorPresenter,
        private ExplorerViewActivityPresenter $activityPresenter,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request, ?User $user, bool $viewerIsParticipant = false): AnalysisExplorerLibraryPageViewModel
    {
        $isLoggedIn = $user instanceof User;
        $viewerIsParticipant = $viewerIsParticipant
            || ($user instanceof User && \in_array('ROLE_PARTICIPANT', $user->getRoles(), true));
        $activeTab = $this->resolveActiveTab($request, $isLoggedIn);
        $activeCategory = $this->normalizeFacet($request->query->getString(ExplorerLibraryQueryKeys::CATEGORY));
        $activeDimension = $this->normalizeFacet($request->query->getString(ExplorerLibraryQueryKeys::DIMENSION));
        $activeChart = $this->normalizeFacet($request->query->getString(ExplorerLibraryQueryKeys::CHART));
        $activeGrain = $this->normalizeFacet($request->query->getString(ExplorerLibraryQueryKeys::GRAIN));
        $searchQuery = $this->normalizeSearchQuery($request->query->getString(ExplorerLibraryQueryKeys::SEARCH));
        $origin = $this->normalizeOrigin($request->query->getString(ExplorerLibraryQueryKeys::ORIGIN));
        $userQuery = $this->normalizeSearchQuery($request->query->getString(ExplorerLibraryQueryKeys::USER));
        $tabViews = $this->viewsForTab($user, $viewerIsParticipant, $activeTab);
        $categoryFilters = $this->valueFilters(
            $request,
            $activeTab,
            $tabViews,
            ExplorerLibraryQueryKeys::CATEGORY,
            $activeCategory,
            fn (SavedExplorerView $view): string => $this->subjectCategoryKey($view),
            fn (SavedExplorerView $view, string $key): string => '' === $key ? '' : $this->subjectCategoryLabel($view),
        );
        $originFilters = $this->originFilters($request, $activeTab, $origin);
        $dimensionFilters = $this->valueFilters(
            $request,
            $activeTab,
            $tabViews,
            ExplorerLibraryQueryKeys::DIMENSION,
            $activeDimension,
            fn (SavedExplorerView $view): string => $this->facetKeys($view)['dimension'],
            fn (SavedExplorerView $view, string $key): string => $this->dimensionLabel($key),
        );
        $chartFilters = $this->valueFilters(
            $request,
            $activeTab,
            $tabViews,
            ExplorerLibraryQueryKeys::CHART,
            $activeChart,
            fn (SavedExplorerView $view): string => $this->facetKeys($view)['chart'],
            fn (SavedExplorerView $view, string $key): string => $this->chartTypeLabel($key),
        );
        $cards = $this->cardsForTab(
            $request,
            $user,
            $activeTab,
            $tabViews,
            $activeCategory,
            $searchQuery,
            $origin,
            $userQuery,
            $activeDimension,
            $activeChart,
            $activeGrain,
        );
        $pagination = $this->paginate($cards, $request->query->getInt(ExplorerLibraryQueryKeys::PAGE, 1));
        $grainFilters = $this->valueFilters(
            $request,
            $activeTab,
            $tabViews,
            ExplorerLibraryQueryKeys::GRAIN,
            $activeGrain,
            fn (SavedExplorerView $view): string => $this->facetKeys($view)['grain'],
            fn (SavedExplorerView $view, string $key): string => $this->grainLabel($key),
        );

        return new AnalysisExplorerLibraryPageViewModel(
            activeTab: $activeTab,
            activeCategory: $activeCategory,
            tabs: $this->tabs($request, $activeTab, $isLoggedIn, $user),
            categoryFilters: $categoryFilters,
            cards: $pagination['cards'],
            isLoggedIn: $isLoggedIn,
            searchQuery: $searchQuery ?? '',
            origin: $origin,
            userQuery: $userQuery ?? '',
            originFilters: $originFilters,
            activeDimension: $activeDimension,
            activeChart: $activeChart,
            activeGrain: $activeGrain,
            dimensionFilters: $dimensionFilters,
            chartFilters: $chartFilters,
            grainFilters: $grainFilters,
            hasActiveFilters: null !== $searchQuery
                || null !== $userQuery
                || null !== $activeCategory
                || self::ORIGIN_ALL !== $origin
                || null !== $activeDimension
                || null !== $activeChart
                || null !== $activeGrain,
            resetUrl: $this->router->generate('app_stats_analysis_library', array_merge(
                $this->scopeQuery($request),
                [ExplorerLibraryQueryKeys::TAB => $activeTab],
            )),
            activeFilterBadges: $this->activeFilterBadges(
                $searchQuery,
                $userQuery,
                $activeCategory,
                $origin,
                $activeDimension,
                $activeChart,
                $activeGrain,
                $categoryFilters,
                $originFilters,
                $dimensionFilters,
                $chartFilters,
                $grainFilters,
            ),
            currentPage: $pagination['currentPage'],
            lastPage: $pagination['lastPage'],
            hasToPaginate: $pagination['hasToPaginate'],
            resultFrom: $pagination['resultFrom'],
            resultTo: $pagination['resultTo'],
            resultTotal: $pagination['resultTotal'],
            assistantUrl: $this->router->generate('app_stats_analysis_assistant', array_merge($this->assistantScopeQuery($request), ['new' => '1'])),
        );
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return array{cards: list<array<string, mixed>>, currentPage: int, lastPage: int, hasToPaginate: bool, resultFrom: int, resultTo: int, resultTotal: int}
     */
    private function paginate(array $cards, int $requestedPage): array
    {
        $total = \count($cards);
        $lastPage = (int) ceil($total / self::PAGE_SIZE);
        $currentPage = max(1, $requestedPage);
        if ($lastPage > 0) {
            $currentPage = min($currentPage, $lastPage);
        }

        $offset = ($currentPage - 1) * self::PAGE_SIZE;
        $slice = \array_slice($cards, $offset, self::PAGE_SIZE);
        $count = \count($slice);

        return [
            'cards' => $slice,
            'currentPage' => $currentPage,
            'lastPage' => max(1, $lastPage),
            'hasToPaginate' => $total > self::PAGE_SIZE,
            'resultFrom' => $count > 0 ? $offset + 1 : 0,
            'resultTo' => $offset + $count,
            'resultTotal' => $total,
        ];
    }

    /**
     * @param list<array{key: string, label: string, active: bool, url: string}> $categoryFilters
     * @param list<array{key: string, label: string, active: bool, url: string}> $originFilters
     * @param list<array{key: string, label: string, active: bool, url: string}> $dimensionFilters
     * @param list<array{key: string, label: string, active: bool, url: string}> $chartFilters
     * @param list<array{key: string, label: string, active: bool, url: string}> $grainFilters
     *
     * @return list<array{label: string, value: string}>
     */
    private function activeFilterBadges(
        ?string $searchQuery,
        ?string $userQuery,
        ?string $activeCategory,
        string $origin,
        ?string $activeDimension,
        ?string $activeChart,
        ?string $activeGrain,
        array $categoryFilters,
        array $originFilters,
        array $dimensionFilters,
        array $chartFilters,
        array $grainFilters,
    ): array {
        $badges = [];
        if (null !== $searchQuery) {
            $badges[] = [
                'label' => $this->translator->trans('label.search', [], 'messages'),
                'value' => $searchQuery,
            ];
        }

        $this->appendSelectedBadge($badges, $categoryFilters, $activeCategory, 'stats.analysis_explorer.data_source.label');
        if (self::ORIGIN_ALL !== $origin) {
            $this->appendSelectedBadge($badges, $originFilters, $origin, 'stats.analysis_explorer.library.origin.label');
        }
        $this->appendSelectedBadge($badges, $dimensionFilters, $activeDimension, 'stats.analysis_explorer.library.card.dimension');
        $this->appendSelectedBadge($badges, $chartFilters, $activeChart, 'stats.analysis_explorer.library.card.chart');
        $this->appendSelectedBadge($badges, $grainFilters, $activeGrain, 'stats.analysis_explorer.library.card.grain');

        if (null !== $userQuery) {
            $badges[] = [
                'label' => $this->translator->trans('stats.analysis_explorer.library.filter.user', [], 'statistics'),
                'value' => $userQuery,
            ];
        }

        return $badges;
    }

    /**
     * @param list<array{label: string, value: string}>                          $badges
     * @param list<array{key: string, label: string, active: bool, url: string}> $filters
     */
    private function appendSelectedBadge(array &$badges, array $filters, ?string $activeKey, string $labelKey): void
    {
        if (null === $activeKey || '' === $activeKey) {
            return;
        }

        foreach ($filters as $filter) {
            if ($filter['key'] !== $activeKey) {
                continue;
            }

            $badges[] = [
                'label' => $this->translator->trans($labelKey, [], 'statistics'),
                'value' => $filter['label'],
            ];

            return;
        }
    }

    private function normalizeSearchQuery(string $search): ?string
    {
        $search = trim($search);

        return '' === $search ? null : $search;
    }

    private function resolveActiveTab(Request $request, bool $isLoggedIn): string
    {
        $tab = $request->query->getString(ExplorerLibraryQueryKeys::TAB, self::TAB_ALL);
        $allowed = [self::TAB_ALL, self::TAB_FAVORITES, self::TAB_MY_VIEWS];

        if (!\in_array($tab, $allowed, true)) {
            return self::TAB_ALL;
        }

        if (!$isLoggedIn && self::TAB_ALL !== $tab) {
            return self::TAB_ALL;
        }

        return $tab;
    }

    private function normalizeFacet(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param list<SavedExplorerView> $views
     *
     * @return list<array<string, mixed>>
     */
    private function cardsForTab(
        Request $request,
        ?User $user,
        string $activeTab,
        array $views,
        ?string $activeCategory,
        ?string $searchQuery,
        string $origin,
        ?string $userQuery,
        ?string $activeDimension,
        ?string $activeChart,
        ?string $activeGrain,
    ): array {
        $cards = [];
        foreach ($views as $view) {
            if (!$this->matchesFacets($view, $activeCategory, $origin, $userQuery, $activeDimension, $activeChart, $activeGrain)) {
                continue;
            }

            if (!$this->matchesSearch($view, $searchQuery)) {
                continue;
            }

            $cards[] = $this->buildCard($request, $view, $user, $activeTab);
        }

        return $this->sortCardsAlphabetically($cards);
    }

    /**
     * @return list<SavedExplorerView>
     */
    private function viewsForTab(?User $user, bool $viewerIsParticipant, string $activeTab): array
    {
        return match ($activeTab) {
            self::TAB_FAVORITES => $user instanceof User
                ? $this->favoriteService->listViewsForUser($user)
                : [],
            self::TAB_MY_VIEWS => $user instanceof User
                ? $this->repository->findByCreatorOrdered($user)
                : [],
            default => $this->overviewViews($user, $viewerIsParticipant),
        };
    }

    /**
     * System views, the viewer's own views, and other participants' public views.
     *
     * @return list<SavedExplorerView>
     */
    private function overviewViews(?User $user, bool $viewerIsParticipant): array
    {
        $views = $this->repository->findAllSystemViewsOrdered();
        if (!$user instanceof User) {
            return $views;
        }

        $views = [...$views, ...$this->repository->findByCreatorOrdered($user)];
        if ($viewerIsParticipant) {
            return [...$views, ...$this->repository->findPublicByOthers($user)];
        }

        return $views;
    }

    private function matchesFacets(
        SavedExplorerView $view,
        ?string $activeCategory,
        string $origin,
        ?string $userQuery,
        ?string $activeDimension,
        ?string $activeChart,
        ?string $activeGrain,
    ): bool {
        if (null !== $activeCategory && $this->subjectCategoryKey($view) !== $activeCategory) {
            return false;
        }

        if (self::ORIGIN_SYSTEM === $origin && !$view->isSystem()) {
            return false;
        }

        if (self::ORIGIN_PUBLIC === $origin && ($view->isSystem() || !$view->isPublic())) {
            return false;
        }

        $facets = $this->facetKeys($view);
        if (null !== $activeDimension && $facets['dimension'] !== $activeDimension) {
            return false;
        }

        if (null !== $activeChart && $facets['chart'] !== $activeChart) {
            return false;
        }

        if (null !== $activeGrain && $facets['grain'] !== $activeGrain) {
            return false;
        }

        return $this->matchesUser($view, $userQuery);
    }

    /**
     * @return array{dimension: string, grain: string, chart: string}
     */
    private function facetKeys(SavedExplorerView $view): array
    {
        $config = $view->getConfigJson();
        $query = \is_array($config['query'] ?? null) ? $config['query'] : [];
        $presentation = \is_array($config['presentation'] ?? null) ? $config['presentation'] : [];
        $rowAxis = \is_array($query['rows'] ?? null) ? $query['rows'] : [];

        return [
            'dimension' => (string) ($rowAxis['dimension'] ?? $query['dimension'] ?? ''),
            'grain' => (string) ($rowAxis['grain'] ?? $query['grain'] ?? ''),
            'chart' => (string) ($presentation['chartType'] ?? ''),
        ];
    }

    private function matchesUser(SavedExplorerView $view, ?string $userQuery): bool
    {
        if (null === $userQuery) {
            return true;
        }

        $creator = $view->getCreatedBy();
        if (!$creator instanceof User) {
            return false;
        }

        $username = $creator->getUsername();
        if (null === $username || '' === $username) {
            return false;
        }

        return str_contains(mb_strtolower($username), mb_strtolower($userQuery));
    }

    private function normalizeOrigin(string $origin): string
    {
        return \in_array($origin, [self::ORIGIN_SYSTEM, self::ORIGIN_PUBLIC], true) ? $origin : self::ORIGIN_ALL;
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
                'url' => $this->router->generate('app_stats_analysis_library', array_merge(
                    $this->scopeQuery($request),
                    [ExplorerLibraryQueryKeys::TAB => $key],
                )),
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
     * @param list<SavedExplorerView>                     $views
     * @param callable(SavedExplorerView): string         $keyOf
     * @param callable(SavedExplorerView, string): string $labelOf
     *
     * @return list<array{key: string, label: string, active: bool, url: string}>
     */
    private function valueFilters(
        Request $request,
        string $activeTab,
        array $views,
        string $queryKey,
        ?string $activeValue,
        callable $keyOf,
        callable $labelOf,
    ): array {
        $options = [];
        foreach ($views as $view) {
            $key = $keyOf($view);
            if ('' === $key) {
                continue;
            }

            $options[$key] ??= [
                'key' => $key,
                'label' => $labelOf($view, $key),
            ];
        }

        $options = array_values($options);
        usort(
            $options,
            static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']),
        );

        $filters = [[
            'key' => '',
            'label' => $this->translator->trans('stats.analysis_explorer.library.category.all', [], 'statistics'),
            'active' => null === $activeValue,
            'url' => $this->router->generate(
                'app_stats_analysis_library',
                $this->facetQuery($request, $activeTab, $queryKey, null),
            ),
        ]];
        foreach ($options as $option) {
            $filters[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'active' => $option['key'] === $activeValue,
                'url' => $this->router->generate(
                    'app_stats_analysis_library',
                    $this->facetQuery($request, $activeTab, $queryKey, $option['key']),
                ),
            ];
        }

        return $filters;
    }

    /**
     * @return list<array{key: string, label: string, active: bool, url: string}>
     */
    private function originFilters(Request $request, string $activeTab, string $origin): array
    {
        $filters = [];
        foreach ([
            self::ORIGIN_ALL => 'stats.analysis_explorer.library.origin.all',
            self::ORIGIN_SYSTEM => 'stats.analysis_explorer.library.origin.system',
            self::ORIGIN_PUBLIC => 'stats.analysis_explorer.library.origin.public',
        ] as $key => $labelKey) {
            $filters[] = [
                'key' => $key,
                'label' => $this->translator->trans($labelKey, [], 'statistics'),
                'active' => $key === $origin,
                'url' => $this->router->generate(
                    'app_stats_analysis_library',
                    $this->facetQuery(
                        $request,
                        $activeTab,
                        ExplorerLibraryQueryKeys::ORIGIN,
                        self::ORIGIN_ALL === $key ? null : $key,
                    ),
                ),
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

        $origin = $this->normalizeOrigin($request->query->getString(ExplorerLibraryQueryKeys::ORIGIN));
        if (self::ORIGIN_ALL !== $origin) {
            $query[ExplorerLibraryQueryKeys::ORIGIN] = $origin;
        }

        $userQuery = $this->normalizeSearchQuery($request->query->getString(ExplorerLibraryQueryKeys::USER));
        if (null !== $userQuery) {
            $query[ExplorerLibraryQueryKeys::USER] = $userQuery;
        }

        foreach ([
            ExplorerLibraryQueryKeys::CATEGORY,
            ExplorerLibraryQueryKeys::DIMENSION,
            ExplorerLibraryQueryKeys::CHART,
            ExplorerLibraryQueryKeys::GRAIN,
        ] as $key) {
            $value = $this->normalizeFacet($request->query->getString($key));
            if (null !== $value) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    private function facetUrl(Request $request, string $tab, string $key, string $value): ?string
    {
        if ('' === $value) {
            return null;
        }

        return $this->router->generate(
            'app_stats_analysis_library',
            $this->facetQuery($request, $tab, $key, $value),
        );
    }

    /**
     * @return array<string, string>
     */
    private function facetQuery(Request $request, string $tab, string $key, ?string $value): array
    {
        $query = $this->tabQuery($request, $tab);
        unset($query[$key]);
        if (null !== $value && '' !== $value) {
            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCard(Request $request, SavedExplorerView $view, ?User $user, string $activeTab): array
    {
        $facets = $this->facetKeys($view);
        $dimension = $facets['dimension'];
        $grain = $facets['grain'];
        $viewId = $view->getId();
        $canFavorite = $user instanceof User && null !== $viewId;
        $categoryKey = $this->subjectCategoryKey($view);
        $categoryLabel = $this->subjectCategoryLabel($view);
        $author = $this->authorPresenter->present($view);
        $activity = $this->activityPresenter->present($view);

        return [
            'id' => $viewId,
            'title' => $this->labelResolver->title($view),
            'description' => $this->labelResolver->description($view) ?? '',
            'dimension' => $this->dimensionLabel($dimension),
            'dimensionKey' => $dimension,
            'dimensionUrl' => $this->facetUrl($request, $activeTab, ExplorerLibraryQueryKeys::DIMENSION, $dimension),
            'grain' => $this->grainLabel($grain),
            'grainKey' => $grain,
            'grainUrl' => $this->facetUrl($request, $activeTab, ExplorerLibraryQueryKeys::GRAIN, $grain),
            'chartType' => $this->chartTypeLabel($facets['chart']),
            'chartTypeKey' => $facets['chart'],
            'chartUrl' => $this->facetUrl($request, $activeTab, ExplorerLibraryQueryKeys::CHART, $facets['chart']),
            'isSystem' => $view->isSystem(),
            'isPublic' => $view->isPublic(),
            'authorName' => $author['name'],
            'authorUrl' => $author['url'],
            'activityKind' => $activity['kind'],
            'activityRelative' => $activity['relativeLabel'],
            'activityAbsolute' => $activity['absoluteLabel'],
            'activityIso' => $activity['iso8601'],
            'viewTypeLabel' => $view->isSystem()
                ? $this->translator->trans('stats.analysis_explorer.view_type.system', [], 'statistics')
                : $this->translator->trans('stats.analysis_explorer.view_type.user', [], 'statistics'),
            'categoryKey' => $categoryKey,
            'categoryLabel' => $categoryLabel,
            'categoryUrl' => $this->facetUrl($request, $activeTab, ExplorerLibraryQueryKeys::CATEGORY, $categoryKey),
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
    private function assistantScopeQuery(Request $request): array
    {
        $query = $this->scopeQuery($request);
        unset(
            $query[StatisticsQueryKeys::PERIOD],
            $query[StatisticsQueryKeys::YEAR],
            $query[StatisticsQueryKeys::MONTH],
            $query[StatisticsQueryKeys::QUARTER],
        );

        return $query;
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

    private function subjectCategoryKey(SavedExplorerView $view): string
    {
        if ('My views' === $view->getCategory()) {
            $dataSource = $view->getConfigJson()['dataSource'] ?? '';
            if (\is_string($dataSource) && '' !== $dataSource) {
                return $this->categoryKey($dataSource);
            }
        }

        return $this->categoryKey($view->getCategory());
    }

    private function subjectCategoryLabel(SavedExplorerView $view): string
    {
        $key = $this->subjectCategoryKey($view);
        $translationKey = 'stats.analysis_explorer.library.category.'.$key;
        $translated = $this->translator->trans($translationKey, [], 'statistics');

        return $translated === $translationKey ? $this->categoryLabel($view->getCategory()) : $translated;
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
            'day' => $this->translator->trans('stats.analysis_explorer.dimension.day', [], 'statistics'),
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
