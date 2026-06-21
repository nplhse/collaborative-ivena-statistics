<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;

final class AllocationsCapabilitiesProvider
{
    private ?DataSourceCapabilities $capabilities = null;

    public function capabilities(): DataSourceCapabilities
    {
        return $this->capabilities ??= new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            dimensions: [
                AnalysisDimensionKey::Time,
                AnalysisDimensionKey::Gender,
                AnalysisDimensionKey::Urgency,
            ],
            metrics: [AnalysisMetricKey::AllocationCount],
            timeGrains: [AnalysisDimensionGrain::Month, AnalysisDimensionGrain::Year],
            chartTypes: [ChartPresentationType::Bar, ChartPresentationType::Line],
            defaultDimension: AnalysisDimensionKey::Time,
            defaultMetric: AnalysisMetricKey::AllocationCount,
            defaultTimeGrain: AnalysisDimensionGrain::Month,
            defaultChartType: ChartPresentationType::Bar,
        );
    }
}
