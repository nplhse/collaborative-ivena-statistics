<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticWidgetType: string
{
    case Kpi = 'kpi';
    case ChartPair = 'chart_pair';
    case Table = 'table';
    case Section = 'section';
    case Distribution = 'distribution';
    case SummaryDeck = 'summary_deck';

    /** Single ApexChart (line or bar chart, e.g. analysis). */
    case SimpleChart = 'simple_chart';
}
