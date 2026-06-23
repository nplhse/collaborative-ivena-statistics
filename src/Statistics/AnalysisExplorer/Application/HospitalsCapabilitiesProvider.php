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
use App\User\Domain\Entity\User;

final class HospitalsCapabilitiesProvider implements DataSourceCapabilitiesProviderInterface
{
    private ?DataSourceCapabilities $defaultCapabilities = null;

    public function __construct(
        private readonly ExplorerMetricCatalog $metricCatalog,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
    ) {
    }

    #[\Override]
    public function supports(AnalysisDataSourceKey $dataSourceKey): bool
    {
        return AnalysisDataSourceKey::Hospitals === $dataSourceKey;
    }

    #[\Override]
    public function capabilitiesFor(?User $user, StatisticsFilter $filter): DataSourceCapabilities
    {
        return $this->defaultCapabilities ??= $this->buildCapabilities();
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    public function metricsForConfig(AnalysisViewConfig $config): array
    {
        return $this->metricCapabilityPolicy->metricsForConfig($config);
    }

    private function buildCapabilities(): DataSourceCapabilities
    {
        $enabledMetrics = $this->metricCatalog->enabledKeysForDataSource(AnalysisDataSourceKey::Hospitals);

        return new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            dimensions: AnalysisDimensionKey::hospitalsCatalog(),
            primaryMetrics: AnalysisMetricKey::primaryHospitalMetricChoices(),
            metrics: $enabledMetrics,
            timeGrains: [AnalysisDimensionGrain::Total],
            chartTypes: [
                ChartPresentationType::Bar,
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Heatmap,
            ],
            defaultDimension: AnalysisDimensionKey::HospitalMasterCohort,
            defaultMetric: AnalysisMetricKey::HospitalCount,
            defaultTimeGrain: AnalysisDimensionGrain::Total,
            defaultChartType: ChartPresentationType::Bar,
        );
    }
}
