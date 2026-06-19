<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum GenericAnalysisChartType: string
{
    case Bar = 'bar';
    case Line = 'line';
    case StackedBar = 'stacked_bar';
    case GroupedBar = 'grouped_bar';
    case HorizontalBar = 'horizontal_bar';
    case PercentStackedBar = 'percent_stacked_bar';
    case Pie = 'pie';
    case Heatmap = 'heatmap';
    case Table = 'table';

    public function supportsApexChart(): bool
    {
        return self::Table !== $this;
    }
}
