<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;

final readonly class ExplorerConfigPreviewFactory
{
    public function fromFormData(
        DataSourceCapabilities $capabilities,
        AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        AnalysisMetricKey $metric,
        ExplorerEditFormData $formData,
    ): AnalysisViewConfig {
        return $this->buildConfig(
            $capabilities,
            $rowAxis,
            $columnAxis,
            $metric,
            $formData->showPercentOfTotal,
            new PresentationConfig(
                chartType: ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar,
                tableLayout: TableLayout::tryFrom($formData->tableLayout) ?? TableLayout::Flat,
            ),
        );
    }

    public function fromConfig(
        DataSourceCapabilities $capabilities,
        AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        AnalysisMetricKey $metricKey,
        AnalysisViewConfig $config,
    ): AnalysisViewConfig {
        return $this->buildConfig(
            $capabilities,
            $rowAxis,
            $columnAxis,
            $metricKey,
            $config->showsPercentOfTotal(),
            $config->presentation,
            $config->statisticsFilter,
            $config->title,
        );
    }

    private function buildConfig(
        DataSourceCapabilities $capabilities,
        AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        AnalysisMetricKey $visualMetricKey,
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
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
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
