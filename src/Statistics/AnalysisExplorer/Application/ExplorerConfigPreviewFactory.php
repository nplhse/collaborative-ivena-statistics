<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;

final readonly class ExplorerConfigPreviewFactory
{
    public function fromFormData(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimension,
        AnalysisMetricKey $metric,
        AnalysisDimensionGrain $timeGrain,
        ExplorerEditFormData $formData,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $metric,
            dimensionKey: $dimension,
            timeGrain: $timeGrain,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar,
            ),
            title: '',
        );
    }

    public function fromConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimensionKey,
        AnalysisMetricKey $metricKey,
        AnalysisDimensionGrain $timeGrain,
        AnalysisViewConfig $config,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $metricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $config->statisticsFilter,
            presentation: $config->presentation,
            title: $config->title,
        );
    }
}
