<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\DataSourceCapabilitiesProviderInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\User\Domain\Entity\User;

final class AllocationsCapabilitiesProvider implements DataSourceCapabilitiesProviderInterface
{
    private ?DataSourceCapabilities $defaultCapabilities = null;

    public function __construct(
        private readonly GenericAnalysisDimensionPolicy $dimensionPolicy,
        private readonly ExplorerMetricCatalog $metricCatalog,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
    ) {
    }

    #[\Override]
    public function supports(AnalysisDataSourceKey $dataSourceKey): bool
    {
        return AnalysisDataSourceKey::Allocations === $dataSourceKey;
    }

    public function capabilities(): DataSourceCapabilities
    {
        return $this->defaultCapabilities ??= $this->buildCapabilities(
            AnalysisDimensionKey::allocationsCatalog(),
        );
    }

    #[\Override]
    public function capabilitiesFor(?User $user, StatisticsFilter $filter): DataSourceCapabilities
    {
        $dimensions = [];
        foreach (AnalysisDimensionKey::allocationsCatalog() as $dimension) {
            if ($dimension->isTemporalPrimary()) {
                $dimensions[] = $dimension;
                continue;
            }

            if ($this->dimensionPolicy->isAllowed($dimension->registryKey(), $filter, $user)) {
                $dimensions[] = $dimension;
            }
        }

        return $this->buildCapabilities($dimensions);
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    public function metricsForConfig(AnalysisViewConfig $config): array
    {
        return $this->metricCapabilityPolicy->metricsForConfig($config);
    }

    /**
     * @param list<AnalysisDimensionKey> $dimensions
     */
    private function buildCapabilities(array $dimensions): DataSourceCapabilities
    {
        $enabledMetrics = $this->metricCatalog->enabledKeysForDataSource(AnalysisDataSourceKey::Allocations);

        return new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            dimensions: $dimensions,
            primaryMetrics: AnalysisMetricKey::primaryMetricChoices(),
            metrics: $enabledMetrics,
            timeGrains: [
                AnalysisDimensionGrain::Month,
                AnalysisDimensionGrain::Year,
                AnalysisDimensionGrain::Quarter,
                AnalysisDimensionGrain::Week,
            ],
            chartTypes: [
                ChartPresentationType::Bar,
                ChartPresentationType::Line,
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Heatmap,
            ],
            defaultDimension: AnalysisDimensionKey::Time,
            defaultMetric: AnalysisMetricKey::AllocationCount,
            defaultTimeGrain: AnalysisDimensionGrain::Month,
            defaultChartType: ChartPresentationType::Bar,
        );
    }
}
