<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\TopList\TopListArrayPaginator;
use App\Statistics\Application\TopList\TopListCatalogCrossReference;
use App\Statistics\Application\TopList\TopListComparison;
use App\Statistics\Application\TopList\TopListComparisonRow;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListPageSizePolicy;
use App\Statistics\Application\TopList\TopListRanking;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class TopListsPagePresenter
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private BenchmarkSelectionFormDataFactory $benchmarkSelectionFormDataFactory,
        private TopListCatalogCrossReference $catalogCrossReference,
    ) {
    }

    /**
     * @param list<TopListDefinitionInterface> $topListDefinitions
     */
    public function present(
        Request $request,
        TopListDefinitionInterface $currentDefinition,
        TopListsRequestModel $requestModel,
        TopListRanking $rankingA,
        array $topListDefinitions,
        ?TopListComparison $comparison = null,
        ?StatisticsFilter $primaryFilter = null,
        ?StatisticsFilter $comparisonFilter = null,
        ?string $sideAHeading = null,
        ?string $sideASubheading = null,
        ?string $sideBHeading = null,
        ?string $sideBSubheading = null,
    ): TopListsPageViewModel {
        $currentLimit = $requestModel->limit;

        $topListSelectUrls = [];
        foreach ($topListDefinitions as $item) {
            $topListSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists_show',
                ['report' => $item->key(), StatisticsQueryKeys::PAGE => null],
            );
        }

        $limitUrls = [];
        foreach ($currentDefinition->allowedLimits() as $allowedLimit) {
            $limitUrls[$allowedLimit->queryValue()] = $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists_show',
                [StatisticsQueryKeys::LIMIT => $allowedLimit->queryValue(), StatisticsQueryKeys::PAGE => null],
            );
        }

        $pageSize = $requestModel->pageSize;
        $pageSizeUrls = [];
        foreach (TopListPageSizePolicy::ALLOWED as $allowedPageSize) {
            $pageSizeUrls[$allowedPageSize] = $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists_show',
                [StatisticsQueryKeys::PER_PAGE => $allowedPageSize, StatisticsQueryKeys::PAGE => null],
            );
        }

        $fullRowCount = $comparison instanceof TopListComparison ? $comparison->count() : $rankingA->count();
        $paginator = TopListArrayPaginator::fromCount(
            $fullRowCount,
            $requestModel->page,
            $pageSize,
        );
        $displayRanking = $rankingA;
        $displayComparison = $comparison;
        if ($displayComparison instanceof TopListComparison) {
            $displayComparison = $displayComparison->pageSlice($paginator->getCurrentPage(), $pageSize);
        } else {
            $displayRanking = $displayRanking->pageSlice($paginator->getCurrentPage(), $pageSize);
        }

        $truncated = $rankingA->truncated || ($comparison instanceof TopListComparison && $comparison->truncated);
        $topListWidget = null;
        $comparisonViewModel = null;
        if ($displayComparison instanceof TopListComparison) {
            $comparisonViewModel = new TopListComparisonViewModel(
                $this->withComparisonLabelTargets($displayComparison->rowsA, $currentDefinition->key()),
                $this->withComparisonLabelTargets($displayComparison->rowsB, $currentDefinition->key()),
                $displayComparison->totalAllocationsA,
                $displayComparison->totalAllocationsB,
                $sideAHeading ?? '',
                $sideASubheading ?? '',
                $sideBHeading ?? '',
                $sideBSubheading ?? '',
                $currentDefinition->tableLabelColumnTranslationKey(),
            );
        } else {
            $topListWidget = $this->withTopListTableControls(
                $currentDefinition->toTableWidget($displayRanking),
                $limitUrls,
                $currentLimit->queryValue(),
                $pageSizeUrls,
                $pageSize,
                $paginator,
                $truncated,
            );
        }

        $selectionFormData = null;
        if ($primaryFilter instanceof StatisticsFilter && $comparisonFilter instanceof StatisticsFilter) {
            $selectionFormData = $this->benchmarkSelectionFormDataFactory->fromFilters($primaryFilter, $comparisonFilter);
        }

        return new TopListsPageViewModel(
            $topListWidget,
            $comparisonViewModel,
            $topListDefinitions,
            $currentDefinition->key(),
            $topListSelectUrls,
            $currentLimit->queryValue(),
            $limitUrls,
            $pageSize,
            $pageSizeUrls,
            $currentDefinition->labelTranslationKey(),
            $currentDefinition->descriptionTranslationKey(),
            $requestModel->compare,
            $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists_show',
                [StatisticsQueryKeys::COMPARE => '1', StatisticsQueryKeys::PAGE => null],
            ),
            $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists_show',
                [],
                StatisticsQueryKeys::REMOVE_COMPARISON_MODE,
            ),
            $truncated,
            $paginator,
            $selectionFormData?->primary,
            $selectionFormData?->comparison,
            $this->preservedQuery($request),
            $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists',
                [StatisticsQueryKeys::REPORT => null],
            ),
            $this->catalogListUrl($currentDefinition->key()),
        );
    }

    /**
     * @param list<TopListComparisonRow> $rows
     *
     * @return list<TopListComparisonRow>
     */
    private function withComparisonLabelTargets(array $rows, string $topListKey): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = $row->withLabelTarget(
                $this->catalogCrossReference->labelRowTarget($topListKey, $row->publicId),
            );
        }

        return $mapped;
    }

    private function catalogListUrl(string $topListKey): ?string
    {
        $route = $this->catalogCrossReference->catalogListRoute($topListKey);
        if (null === $route) {
            return null;
        }

        return $this->statisticsNavigationUrlBuilder->generate($route);
    }

    /**
     * @param array<int|string, string> $limitUrls
     * @param array<int, string>        $pageSizeUrls
     */
    private function withTopListTableControls(
        StatisticWidget $widget,
        array $limitUrls,
        int|string $currentLimit,
        array $pageSizeUrls,
        int $currentPageSize,
        TopListArrayPaginator $paginator,
        bool $truncated,
    ): StatisticWidget {
        if (StatisticWidgetType::Table !== $widget->type) {
            return $widget;
        }

        $payload = $widget->payload;
        $payload['rankingDepth'] = [
            'urls' => $limitUrls,
            'current' => $currentLimit,
        ];
        $payload['limitFooter'] = new TopListTableLimitFooter($pageSizeUrls, $currentPageSize, $paginator, $truncated)->toArray();

        return new StatisticWidget($widget->type, $widget->id, $payload, $widget->title, $widget->actions);
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function preservedQuery(Request $request): array
    {
        $query = [];
        foreach ($request->query->all() as $key => $value) {
            if (\is_bool($value) || \is_float($value) || \is_int($value) || \is_string($value)) {
                $query[$key] = $value;
            }
        }

        unset($query[StatisticsQueryKeys::PAGE]);

        $report = $request->attributes->get('report');
        if (\is_string($report) && '' !== $report) {
            $query[StatisticsQueryKeys::REPORT] = $report;
        }

        return $query;
    }

    /**
     * @param array<string, scalar|null> $replace
     * @param list<string>               $removeKeys
     */
    private function statisticsPageUrl(
        Request $request,
        string $routeName,
        array $replace = [],
        array $removeKeys = [],
    ): string {
        return $this->statisticsNavigationUrlBuilder->build($request, $routeName, $replace, $removeKeys);
    }
}
