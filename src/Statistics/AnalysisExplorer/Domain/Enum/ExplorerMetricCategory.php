<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerMetricCategory: string
{
    case Count = 'count';
    case Distribution = 'distribution';
    case Rate = 'rate';
    case Statistical = 'statistical';
}
