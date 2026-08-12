<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class TopListsPagePresenter
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @param list<TopListDefinitionInterface> $topListDefinitions
     */
    public function present(
        Request $request,
        TopListDefinitionInterface $currentDefinition,
        TopListsRequestModel $requestModel,
        StatisticWidget $topListWidget,
        array $topListDefinitions,
    ): TopListsPageViewModel {
        $currentLimit = $requestModel->limit;

        $topListSelectUrls = [];
        foreach ($topListDefinitions as $item) {
            $topListSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists',
                ['report' => $item->key()],
            );
        }

        $limitUrls = [];
        foreach ($currentDefinition->allowedLimits() as $limit) {
            $limitUrls[$limit] = $this->statisticsPageUrl(
                $request,
                'app_stats_top_lists',
                ['limit' => $limit],
            );
        }

        $topListWidget = $this->withTopListTableLimitFooter($topListWidget, $limitUrls, $currentLimit);

        return new TopListsPageViewModel(
            $topListWidget,
            $topListDefinitions,
            $currentDefinition->key(),
            $topListSelectUrls,
            $currentLimit,
            $currentDefinition->labelTranslationKey(),
            $currentDefinition->descriptionTranslationKey(),
        );
    }

    /**
     * @param array<int, string> $limitUrls
     */
    private function withTopListTableLimitFooter(StatisticWidget $widget, array $limitUrls, int $currentLimit): StatisticWidget
    {
        if (StatisticWidgetType::Table !== $widget->type) {
            return $widget;
        }

        $payload = $widget->payload;
        $payload['limitFooter'] = new TopListTableLimitFooter($limitUrls, $currentLimit)->toArray();

        return new StatisticWidget($widget->type, $widget->id, $payload, $widget->title, $widget->actions);
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
