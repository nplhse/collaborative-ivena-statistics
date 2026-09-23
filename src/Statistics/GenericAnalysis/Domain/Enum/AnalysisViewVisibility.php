<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisViewVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
