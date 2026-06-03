<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisDimensionType: string
{
    case Temporal = 'temporal';
    case Categorical = 'categorical';
    case Boolean = 'boolean';
    case Numeric = 'numeric';
}
