<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;

final readonly class DataSourceCapabilities
{
    /**
     * @param list<AnalysisDimensionKey>   $dimensions
     * @param list<AnalysisMetricKey>      $primaryMetrics
     * @param list<AnalysisMetricKey>      $metrics
     * @param list<AnalysisDimensionGrain> $timeGrains
     * @param list<ChartPresentationType>  $chartTypes
     */
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public array $dimensions,
        public array $primaryMetrics,
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
        if ($config->visualMetricKey->isDistributionProfile()) {
            return [ChartPresentationType::BoxPlot];
        }

        if ($this->usesMultiMetricComparisonChart($config)) {
            return [ChartPresentationType::GroupedBar];
        }

        if ($this->usesMultiSeriesChart($config)) {
            return [
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Line,
                ChartPresentationType::Heatmap,
            ];
        }

        return [ChartPresentationType::Bar, ChartPresentationType::Line];
    }

    public function defaultChartTypeFor(AnalysisViewConfig $config): ChartPresentationType
    {
        if ($config->visualMetricKey->isDistributionProfile()) {
            return ChartPresentationType::BoxPlot;
        }

        if ($this->usesMultiMetricComparisonChart($config)) {
            return ChartPresentationType::GroupedBar;
        }

        if ($this->usesMultiSeriesChart($config)) {
            return ChartPresentationType::GroupedBar;
        }

        return ChartPresentationType::Bar;
    }

    public function supports(AnalysisViewConfig $config): bool
    {
        if (!$this->supportsAxis($config->rowAxis)) {
            return false;
        }

        if ($config->columnAxis instanceof AnalysisAxisRef && !$this->supportsColumnAxis($config->rowAxis, $config->columnAxis)) {
            return false;
        }

        foreach ($config->metricKeys as $metricKey) {
            if (!\in_array($metricKey, $this->metrics, true)) {
                return false;
            }
        }

        if (!\in_array($config->visualMetricKey, $config->metricKeys, true)) {
            return false;
        }

        if (!\in_array($config->visualMetricKey, $this->primaryMetrics, true)) {
            return false;
        }

        return \in_array($config->presentation->chartType, $this->chartTypesFor($config), true);
    }

    public function usesMultiSeriesChart(AnalysisViewConfig $config): bool
    {
        if ($config->hasColumnAxis()) {
            return true;
        }

        return AnalysisDataSourceKey::Hospitals === $config->dataSourceKey
            && ExplorerHospitalPopulationMode::Compare === $config->hospitalPopulationMode;
    }

    public function usesMultiMetricComparisonChart(AnalysisViewConfig $config): bool
    {
        if ($config->hasColumnAxis()) {
            return false;
        }

        return \count($config->metricKeys) > 1
            && AnalysisDimensionKey::PeriodTotal === $config->rowAxis->dimensionKey;
    }

    public function supportsAxis(AnalysisAxisRef $axis): bool
    {
        if (!\in_array($axis->dimensionKey, $this->dimensions, true)) {
            return false;
        }

        $grain = $axis->resolvedGrain();

        if (!\in_array($grain, $this->timeGrainsFor($axis->dimensionKey), true)) {
            return false;
        }

        return !(AnalysisDimensionKey::Time === $axis->dimensionKey && AnalysisDimensionGrain::Total === $grain);
    }

    public function supportsColumnAxis(AnalysisAxisRef $rowAxis, AnalysisAxisRef $columnAxis): bool
    {
        if ($rowAxis->dimensionKey === $columnAxis->dimensionKey) {
            return false;
        }

        return $this->supportsAxis($columnAxis);
    }

    /**
     * @return list<AnalysisDimensionKey>
     */
    public function columnDimensionsFor(AnalysisAxisRef $rowAxis): array
    {
        $options = [];
        foreach ($this->dimensions as $dimension) {
            if ($dimension === $rowAxis->dimensionKey) {
                continue;
            }

            $candidate = new AnalysisAxisRef(
                $dimension,
                $dimension->isTemporalPrimary() ? $this->defaultTimeGrain : AnalysisDimensionGrain::Total,
            );

            if ($this->supportsColumnAxis($rowAxis, $candidate)) {
                $options[] = $dimension;
            }
        }

        return $options;
    }
}
