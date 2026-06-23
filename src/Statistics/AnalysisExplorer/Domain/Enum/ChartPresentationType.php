<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ChartPresentationType: string
{
    case Bar = 'bar';
    case Line = 'line';
    case GroupedBar = 'grouped_bar';
    case StackedBar = 'stacked_bar';
    case Heatmap = 'heatmap';
}
