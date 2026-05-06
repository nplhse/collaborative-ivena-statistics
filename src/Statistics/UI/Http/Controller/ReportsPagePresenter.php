<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Report\ReportDefinitionInterface;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class ReportsPagePresenter
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @param list<ReportDefinitionInterface> $reportDefinitions
     */
    public function present(
        Request $request,
        ReportDefinitionInterface $currentDefinition,
        ReportsRequestModel $requestModel,
        StatisticWidget $reportWidget,
        array $reportDefinitions,
    ): ReportsPageViewModel {
        $currentLimit = $requestModel->limit;

        $reportSelectUrls = [];
        foreach ($reportDefinitions as $item) {
            $reportSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['report' => $item->key()],
            );
        }

        $limitUrls = [];
        foreach ($currentDefinition->allowedLimits() as $limit) {
            $limitUrls[$limit] = $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['limit' => $limit],
            );
        }

        $reportWidget = $this->withReportTableLimitFooter($reportWidget, $limitUrls, $currentLimit);

        return new ReportsPageViewModel(
            $reportWidget,
            $reportDefinitions,
            $currentDefinition->key(),
            $reportSelectUrls,
            $currentLimit,
        );
    }

    /**
     * @param array<int, string> $limitUrls
     */
    private function withReportTableLimitFooter(StatisticWidget $widget, array $limitUrls, int $currentLimit): StatisticWidget
    {
        if (StatisticWidgetType::Table !== $widget->type) {
            return $widget;
        }

        $payload = $widget->payload;
        $payload['limitFooter'] = (new ReportTableLimitFooter($limitUrls, $currentLimit))->toArray();

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
