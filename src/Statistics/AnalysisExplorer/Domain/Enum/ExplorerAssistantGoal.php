<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerAssistantGoal: string
{
    case TimeSeries = 'time_series';
    case Distribution = 'distribution';
    case Toplist = 'toplist';
    case Matrix = 'matrix';
}
