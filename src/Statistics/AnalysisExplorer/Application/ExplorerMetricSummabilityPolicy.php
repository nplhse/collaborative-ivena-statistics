<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerMetricCategory;

final readonly class ExplorerMetricSummabilityPolicy
{
    public function isSummable(AnalysisMetricKey $metricKey): bool
    {
        $category = $metricKey->metricCategory();

        if (ExplorerMetricCategory::Count === $category
            || ExplorerMetricCategory::Distribution === $category) {
            return true;
        }

        return match ($metricKey) {
            AnalysisMetricKey::SumBeds,
            AnalysisMetricKey::TotalAllocations => true,
            default => false,
        };
    }
}
