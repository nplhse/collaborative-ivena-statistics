<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;

final readonly class DataSourceCapabilities
{
    /**
     * @param list<AnalysisDimensionKey>   $dimensions
     * @param list<AnalysisMetricKey>      $metrics
     * @param list<AnalysisDimensionGrain> $timeGrains
     * @param list<ChartPresentationType>  $chartTypes
     */
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public array $dimensions,
        public array $metrics,
        public array $timeGrains,
        public array $chartTypes,
        public AnalysisDimensionKey $defaultDimension,
        public AnalysisMetricKey $defaultMetric,
        public AnalysisDimensionGrain $defaultTimeGrain,
        public ChartPresentationType $defaultChartType,
    ) {
    }

    /**
     * @return list<AnalysisDimensionGrain>
     */
    public function timeGrainsFor(AnalysisDimensionKey $dimension): array
    {
        if (AnalysisDimensionKey::Time !== $dimension) {
            return [];
        }

        return $this->timeGrains;
    }

    public function supports(AnalysisViewConfig $config): bool
    {
        if (!\in_array($config->dimensionKey, $this->dimensions, true)) {
            return false;
        }

        if (!\in_array($config->metricKey, $this->metrics, true)) {
            return false;
        }

        if (!\in_array($config->presentation->chartType, $this->chartTypes, true)) {
            return false;
        }

        if (AnalysisDimensionKey::Time === $config->dimensionKey) {
            return $config->timeGrain instanceof AnalysisDimensionGrain
                && \in_array($config->timeGrain, $this->timeGrains, true);
        }

        return !$config->timeGrain instanceof AnalysisDimensionGrain;
    }
}
