<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisViewSource: string
{
    case System = 'system';
    case Saved = 'saved';
}
