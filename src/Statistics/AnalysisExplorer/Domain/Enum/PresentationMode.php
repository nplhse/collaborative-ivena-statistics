<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum PresentationMode: string
{
    case Chart = 'chart';
    case Table = 'table';
}
