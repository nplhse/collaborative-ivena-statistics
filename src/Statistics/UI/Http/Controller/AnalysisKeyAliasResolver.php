<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final class AnalysisKeyAliasResolver
{
    public function resolve(string $requestedAnalysis): string
    {
        return match ($requestedAnalysis) {
            'pivot' => 'allocation_pivot',
            'allocations_over_time' => 'allocations_by_month',
            default => $requestedAnalysis,
        };
    }
}
