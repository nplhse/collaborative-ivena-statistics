<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Application\AnalysisViewLibraryService;
use App\Statistics\GenericAnalysis\Application\AnalysisViewRecentService;
use App\Statistics\GenericAnalysis\Application\FavoriteAnalysisViewService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\AnalyticsLibraryQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalyticsLibraryPageViewModelFactory
{
    public function __construct(
        private AnalysisViewLibraryService $libraryService,
        private AnalysisViewRegistry $viewRegistry,
        private FavoriteAnalysisViewService $favoriteService,
        private AnalysisViewRecentService $recentService,
        private MetricRegistry $metricRegistry,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request, ?User $user): AnalyticsLibraryPageViewModel
    {
        $activeTab = $request->query->getString(AnalyticsLibraryQueryKeys::TAB, 'recommended');
        $search = 'categories' === $activeTab
            ? trim($request->query->getString(AnalyticsLibraryQueryKeys::SEARCH))
            : '';
        $categoryFilter = $request->query->getString(AnalyticsLibraryQueryKeys::CATEGORY);
        $category = '' !== $categoryFilter
            ? AnalysisViewCategory::tryFrom($categoryFilter)
            : null;

        $favoriteKeys = $this->favoriteKeys($user);
        $views = $this->libraryService->listViews('' !== $search ? $search : null, $category);

        return new AnalyticsLibraryPageViewModel(
            searchQuery: $search,
            activeTab: $activeTab,
            activeCategory: $category?->value,
            tabs: $this->tabs($request, $activeTab),
            categories: $this->categoryOptions(),
            recommendedCards: $this->buildCards(
                $request,
                $this->libraryService->recommendedViews($user),
                $favoriteKeys,
                $user,
            ),
            favoriteCards: $this->buildFavoriteCards($request, $user),
            recentCards: $user instanceof User
                ? $this->buildCards($request, $this->recentService->lastUsed($user), $favoriteKeys, $user)
                : [],
            frequentCards: $user instanceof User
                ? $this->buildCards($request, $this->recentService->mostFrequent($user), $favoriteKeys, $user)
                : [],
            categoryCards: $this->buildCards($request, $views, $favoriteKeys, $user),
            categoryFilters: $this->categoryFilters($request, $category?->value),
            builderUrl: $this->router->generate('app_stats_analytics_builder', $this->scopeQuery($request)),
            isLoggedIn: $user instanceof User,
        );
    }

    /**
     * @return array<string, bool>
     */
    private function favoriteKeys(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        $keys = [];
        foreach ($this->favoriteService->listForUser($user) as $favorite) {
            if (AnalysisViewSource::System === $favorite->getSource()) {
                $systemViewKey = $favorite->getSystemViewKey();
                if (null !== $systemViewKey) {
                    $keys[$systemViewKey] = true;
                }

                continue;
            }

            if (AnalysisViewSource::Saved === $favorite->getSource()) {
                $savedId = $favorite->getSavedView()?->getId();
                if (null !== $savedId) {
                    $keys['saved_'.$savedId] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<AnalysisViewDefinition> $views
     * @param array<string, bool>          $favoriteKeys
     *
     * @return list<array<string, mixed>>
     */
    private function buildCards(
        Request $request,
        array $views,
        array $favoriteKeys,
        ?User $user,
    ): array {
        $cards = [];
        foreach ($views as $view) {
            $cards[] = $this->buildCard($request, $view, $favoriteKeys, $user);
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFavoriteCards(Request $request, ?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        $cards = [];
        $favoriteKeys = $this->favoriteKeys($user);
        foreach ($this->favoriteService->listForUser($user) as $favorite) {
            if (AnalysisViewSource::System === $favorite->getSource()) {
                $key = $favorite->getSystemViewKey();
                if (null !== $key && $this->viewRegistry->has($key)) {
                    $cards[] = $this->buildCard(
                        $request,
                        $this->viewRegistry->get($key),
                        $favoriteKeys,
                        $user,
                    );
                }

                continue;
            }

            $saved = $favorite->getSavedView();
            if ($saved instanceof SavedAnalysisView) {
                $cards[] = $this->buildCard(
                    $request,
                    $this->toSavedViewDefinition($saved),
                    $favoriteKeys,
                    $user,
                );
            }
        }

        return $cards;
    }

    private function toSavedViewDefinition(SavedAnalysisView $saved): AnalysisViewDefinition
    {
        $config = $saved->getConfig();
        $baseView = null;
        $sourceKey = $saved->getSourceSystemViewKey();
        if (null !== $sourceKey && $this->viewRegistry->has($sourceKey)) {
            $baseView = $this->viewRegistry->get($sourceKey);
        }

        $savedId = $saved->getId();
        if (null === $savedId) {
            throw new \LogicException('Saved analysis view must be persisted before use.');
        }

        return new AnalysisViewDefinition(
            key: 'saved_'.$savedId,
            title: $saved->getTitle(),
            description: $saved->getDescription() ?? '',
            category: $baseView instanceof AnalysisViewDefinition ? $baseView->category : AnalysisViewCategory::TimeAndTrends,
            tags: $baseView instanceof AnalysisViewDefinition ? $baseView->tags : ['saved'],
            primaryDimensionKey: $config->primaryDimensionKey,
            secondaryDimensionKey: $config->secondaryDimensionKey,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            chartType: null !== $config->chartType
                ? GenericAnalysisChartType::from($config->chartType)
                : ($baseView instanceof AnalysisViewDefinition ? $baseView->chartType : GenericAnalysisChartType::Bar),
            allowedChartTypes: $baseView instanceof AnalysisViewDefinition ? $baseView->allowedChartTypes : [],
            includeNullBuckets: $config->includeNullBuckets,
            legacyPresetKey: $sourceKey,
        );
    }

    /**
     * @param array<string, bool> $favoriteKeys
     *
     * @return array<string, mixed>
     */
    private function buildCard(
        Request $request,
        AnalysisViewDefinition $view,
        array $favoriteKeys,
        ?User $user,
    ): array {
        $isSaved = str_starts_with($view->key, 'saved_');
        $openUrl = $isSaved
            ? $this->router->generate('app_stats_analytics_saved', array_merge(
                ['id' => (int) substr($view->key, 6)],
                $this->scopeQuery($request),
            ))
            : $this->router->generate('app_stats_analytics_view', array_merge(
                ['viewKey' => $view->key],
                $this->scopeQuery($request),
            ));

        $metricLabels = [];
        foreach ($view->resolvedMetricKeys() as $metricKey) {
            if ($this->metricRegistry->has($metricKey)) {
                $metricLabels[] = $this->metricRegistry->get($metricKey)->label;
            }
        }

        return [
            'key' => $view->key,
            'title' => $view->title,
            'description' => $view->description,
            'category' => $this->translator->trans('stats.analytics_library.category.'.$view->category->value),
            'categoryKey' => $view->category->value,
            'categoryUrl' => $this->router->generate('app_stats_analytics_library', $this->categoriesTabQuery(
                $request,
                [AnalyticsLibraryQueryKeys::CATEGORY => $view->category->value],
            )),
            'tags' => $view->tags,
            'chartType' => $this->chartLabel($view->chartType),
            'metrics' => $metricLabels,
            'openUrl' => $openUrl,
            'isFavorite' => isset($favoriteKeys[$view->key]),
            'canFavorite' => $user instanceof User,
            'favoriteUrl' => $user instanceof User && !$isSaved
                ? $this->router->generate('app_stats_analytics_favorite_toggle', ['viewKey' => $view->key])
                : null,
        ];
    }

    /**
     * @return list<array{key: string, label: string, active: bool, url: string}>
     */
    private function tabs(Request $request, string $activeTab): array
    {
        $definitions = [
            'recommended' => 'stats.analytics_library.tab.recommended',
            'favorites' => 'stats.analytics_library.tab.favorites',
            'recent' => 'stats.analytics_library.tab.recent',
            'categories' => 'stats.analytics_library.tab.categories',
        ];

        $tabs = [];
        foreach ($definitions as $key => $labelKey) {
            $tabs[] = [
                'key' => $key,
                'label' => $this->translator->trans($labelKey),
                'active' => $key === $activeTab,
                'url' => $this->router->generate('app_stats_analytics_library', array_merge(
                    $this->scopeQuery($request),
                    [AnalyticsLibraryQueryKeys::TAB => $key],
                )),
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function categoryOptions(): array
    {
        $options = [];
        foreach (AnalysisViewCategory::cases() as $category) {
            $options[] = [
                'key' => $category->value,
                'label' => $this->translator->trans('stats.analytics_library.category.'.$category->value),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{key: string, label: string, active: bool, url: string}>
     */
    private function categoryFilters(Request $request, ?string $activeCategory): array
    {
        $filters = [[
            'key' => '',
            'label' => $this->translator->trans('stats.analytics_library.category.all'),
            'active' => null === $activeCategory || '' === $activeCategory,
            'url' => $this->router->generate('app_stats_analytics_library', $this->categoriesTabQuery($request)),
        ]];

        foreach (AnalysisViewCategory::cases() as $category) {
            $filters[] = [
                'key' => $category->value,
                'label' => $this->translator->trans('stats.analytics_library.category.'.$category->value),
                'active' => $category->value === $activeCategory,
                'url' => $this->router->generate('app_stats_analytics_library', $this->categoriesTabQuery(
                    $request,
                    [AnalyticsLibraryQueryKeys::CATEGORY => $category->value],
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
    private function categoriesTabQuery(Request $request, array $extra = []): array
    {
        $query = array_merge(
            $this->scopeQuery($request),
            [AnalyticsLibraryQueryKeys::TAB => 'categories'],
            $extra,
        );

        $search = trim($request->query->getString(AnalyticsLibraryQueryKeys::SEARCH));
        if ('' !== $search) {
            $query[AnalyticsLibraryQueryKeys::SEARCH] = $search;
        }

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

    private function chartLabel(GenericAnalysisChartType $type): string
    {
        return $this->translator->trans(match ($type) {
            GenericAnalysisChartType::Bar => 'stats.generic_analysis.chart.type.bar',
            GenericAnalysisChartType::Line => 'stats.generic_analysis.chart.type.line',
            GenericAnalysisChartType::StackedBar => 'stats.generic_analysis.chart.type.stacked_bar',
            GenericAnalysisChartType::GroupedBar => 'stats.generic_analysis.chart.type.grouped_bar',
            GenericAnalysisChartType::HorizontalBar => 'stats.generic_analysis.chart.type.horizontal_bar',
            GenericAnalysisChartType::PercentStackedBar => 'stats.generic_analysis.chart.type.percent_stacked_bar',
            GenericAnalysisChartType::Pie => 'stats.generic_analysis.chart.type.pie',
            GenericAnalysisChartType::Heatmap => 'stats.generic_analysis.chart.type.heatmap',
            GenericAnalysisChartType::Table => 'stats.generic_analysis.chart.type.table',
        });
    }
}
