<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerMetricCategory;

final readonly class ExplorerMetricSummabilityPolicy
{
    public function __construct(
        private ExplorerMetricCatalog $metricCatalog,
    ) {
    }

    public function isSummable(AnalysisMetricKey $metricKey): bool
    {
        if (!$this->metricCatalog->has($metricKey)) {
            return AnalysisMetricKey::AllocationCount === $metricKey;
        }

        $category = $this->metricCatalog->get($metricKey)->metricCategory();

        return ExplorerMetricCategory::Count === $category
            || ExplorerMetricCategory::Distribution === $category;
    }
}
