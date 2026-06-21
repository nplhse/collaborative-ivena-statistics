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
        return $this->buildConfig($capabilities, $dimension, $metric, $timeGrain, $formData->showPercentOfTotal, new PresentationConfig(
            chartType: ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar,
        ));
    }

    public function fromConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimensionKey,
        AnalysisMetricKey $metricKey,
        AnalysisDimensionGrain $timeGrain,
        AnalysisViewConfig $config,
    ): AnalysisViewConfig {
        return $this->buildConfig(
            $capabilities,
            $dimensionKey,
            $metricKey,
            $timeGrain,
            $config->showsPercentOfTotal(),
            $config->presentation,
            $config->statisticsFilter,
            $config->title,
        );
    }

    private function buildConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimensionKey,
        AnalysisMetricKey $visualMetricKey,
        AnalysisDimensionGrain $timeGrain,
        bool $showPercentOfTotal,
        PresentationConfig $presentation,
        ?StatisticsFilter $statisticsFilter = null,
        string $title = '',
    ): AnalysisViewConfig {
        $metricKeys = $this->resolveMetricKeys($visualMetricKey, $showPercentOfTotal);

        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $statisticsFilter ?? new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: $presentation,
            title: $title,
        );
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    private function resolveMetricKeys(AnalysisMetricKey $visualMetricKey, bool $showPercentOfTotal): array
    {
        $metricKeys = [$visualMetricKey];
        if ($showPercentOfTotal && AnalysisMetricKey::AllocationCount === $visualMetricKey) {
            $metricKeys[] = AnalysisMetricKey::PercentOfTotal;
        }

        return $metricKeys;
    }
}
