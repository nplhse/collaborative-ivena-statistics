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
        if ($dimension->isTemporalPrimary()) {
            return $this->timeGrains;
        }

        return [
            AnalysisDimensionGrain::Total,
            AnalysisDimensionGrain::Month,
            AnalysisDimensionGrain::Year,
        ];
    }

    /**
     * @return list<ChartPresentationType>
     */
    public function chartTypesFor(AnalysisViewConfig $config): array
    {
        if ($this->usesMultiSeriesChart($config)) {
            return [
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Line,
            ];
        }

        return [ChartPresentationType::Bar, ChartPresentationType::Line];
    }

    public function defaultChartTypeFor(AnalysisViewConfig $config): ChartPresentationType
    {
        if ($this->usesMultiSeriesChart($config)) {
            return ChartPresentationType::GroupedBar;
        }

        return ChartPresentationType::Bar;
    }

    public function supports(AnalysisViewConfig $config): bool
    {
        if (!\in_array($config->dimensionKey, $this->dimensions, true)) {
            return false;
        }

        if (!\in_array($config->metricKey, $this->metrics, true)) {
            return false;
        }

        if (!\in_array($config->presentation->chartType, $this->chartTypesFor($config), true)) {
            return false;
        }

        $grain = $config->timeGrain;
        if (!$grain instanceof AnalysisDimensionGrain) {
            return false;
        }

        if (!\in_array($grain, $this->timeGrainsFor($config->dimensionKey), true)) {
            return false;
        }

        return !(AnalysisDimensionKey::Time === $config->dimensionKey && AnalysisDimensionGrain::Total === $grain);
    }

    public function usesMultiSeriesChart(AnalysisViewConfig $config): bool
    {
        if ($config->dimensionKey->isTemporalPrimary()) {
            return false;
        }

        return $config->timeGrain instanceof AnalysisDimensionGrain
            && $config->timeGrain->isTemporal();
    }
}
