<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisFilterOperator: string
{
    case Equals = 'equals';
    case In = 'in';
}
