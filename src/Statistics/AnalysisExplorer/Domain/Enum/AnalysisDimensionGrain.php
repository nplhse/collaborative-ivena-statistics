<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDimensionGrain: string
{
    case Month = 'month';
    case Year = 'year';
}
