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
    private const array ALLOWED_LIMITS = [10, 25, 50];

    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @param list<ReportDefinitionInterface> $reportDefinitions
     */
    public function present(
        Request $request,
        string $currentReportKey,
        StatisticWidget $reportWidget,
        array $reportDefinitions,
    ): ReportsPageViewModel {
        $currentLimit = $this->resolveReportLimit($request->query->all()['limit'] ?? null);

        $reportSelectUrls = [];
        foreach ($reportDefinitions as $item) {
            $reportSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['report' => $item->key()],
            );
        }

        $limitUrls = [];
        foreach (self::ALLOWED_LIMITS as $limit) {
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
            $currentReportKey,
            $reportSelectUrls,
            $currentLimit,
        );
    }

    public function resolveReportLimit(mixed $rawLimit): int
    {
        if (null === $rawLimit || '' === (string) $rawLimit) {
            return 25;
        }

        $parsed = filter_var((string) $rawLimit, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED_LIMITS, true)) {
            return $parsed;
        }

        return 25;
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
        $payload['limitFooter'] = [
            'urls' => $limitUrls,
            'current' => $currentLimit,
        ];

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
