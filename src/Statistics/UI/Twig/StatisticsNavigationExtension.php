<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StatisticsNavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly StatisticsNavigationUrlBuilder $urlBuilder,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('statistics_nav_url', $this->statisticsNavUrl(...)),
            new TwigFunction('statistics_widget_template', $this->statisticsWidgetTemplate(...)),
        ];
    }

    public function statisticsNavUrl(StatisticWidgetNavigationTarget $target): string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return '#';
        }

        return $this->urlBuilder->buildFromTarget($request, $target);
    }

    public function statisticsWidgetTemplate(StatisticWidgetType $type, bool $component = false): string
    {
        return match ([$type, $component]) {
            [StatisticWidgetType::Table, true] => '@Statistics/dashboard/components/_widget_table_component.html.twig',
            [StatisticWidgetType::SimpleChart, true] => '@Statistics/dashboard/components/_widget_simple_chart_component.html.twig',
            [StatisticWidgetType::PivotTable, true] => '@Statistics/dashboard/components/_widget_pivot_component.html.twig',
            [StatisticWidgetType::SummaryDeck, false] => '@Statistics/dashboard/_hospital_summary_deck.html.twig',
            [StatisticWidgetType::Section, false] => '@Statistics/dashboard/_section_card.html.twig',
            [StatisticWidgetType::Kpi, false] => '@Statistics/dashboard/_kpi_card.html.twig',
            [StatisticWidgetType::ChartPair, false] => '@Statistics/dashboard/_chart_pair_row.html.twig',
            [StatisticWidgetType::Distribution, false] => '@Statistics/dashboard/_distribution_card.html.twig',
            [StatisticWidgetType::SimpleChart, false] => '@Statistics/dashboard/_simple_chart_card.html.twig',
            [StatisticWidgetType::Table, false] => '@Statistics/dashboard/_table_card.html.twig',
            [StatisticWidgetType::PivotTable, false] => '@Statistics/dashboard/_pivot_table_card.html.twig',
            default => '@Statistics/dashboard/_statistic_widget.html.twig',
        };
    }
}
