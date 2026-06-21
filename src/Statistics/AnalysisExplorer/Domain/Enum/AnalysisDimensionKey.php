<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDimensionKey: string
{
    case Time = 'time';
    case Gender = 'gender';
    case Urgency = 'urgency';
}
