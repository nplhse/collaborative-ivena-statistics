<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\User\Domain\Entity\User;

final class AllocationsCapabilitiesProvider
{
    private ?DataSourceCapabilities $defaultCapabilities = null;

    public function __construct(
        private readonly GenericAnalysisDimensionPolicy $dimensionPolicy,
    ) {
    }

    public function capabilities(): DataSourceCapabilities
    {
        return $this->defaultCapabilities ??= $this->buildCapabilities(
            AnalysisDimensionKey::allocationsCatalog(),
        );
    }

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
     * @param list<AnalysisDimensionKey> $dimensions
     */
    private function buildCapabilities(array $dimensions): DataSourceCapabilities
    {
        return new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            dimensions: $dimensions,
            metrics: [AnalysisMetricKey::AllocationCount],
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
            ],
            defaultDimension: AnalysisDimensionKey::Time,
            defaultMetric: AnalysisMetricKey::AllocationCount,
            defaultTimeGrain: AnalysisDimensionGrain::Month,
            defaultChartType: ChartPresentationType::Bar,
        );
    }
}
