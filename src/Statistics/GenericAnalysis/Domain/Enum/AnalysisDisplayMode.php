<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisDisplayMode: string
{
    case Chart = 'chart';
    case Table = 'table';
    case PivotTable = 'pivot_table';
}
