<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisPeriodAppliesTo: string
{
    case AllMetrics = 'all_metrics';
    case AllocationDerivedOnly = 'allocation_derived_only';
}
