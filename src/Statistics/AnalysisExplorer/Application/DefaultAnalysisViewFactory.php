<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class DefaultAnalysisViewFactory
{
    public function __construct(
        private ExplorerTitleFactory $titleFactory,
    ) {
    }

    public function createDefault(StatisticsFilter $statisticsFilter): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: $statisticsFilter,
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
            ),
            title: $this->titleFactory->titleFor(AnalysisDimensionKey::Time),
        );
    }
}
