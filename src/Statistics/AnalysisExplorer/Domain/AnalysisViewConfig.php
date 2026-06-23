<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class AnalysisViewConfig
{
    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public array $metricKeys,
        public AnalysisMetricKey $visualMetricKey,
        public AnalysisAxisRef $rowAxis,
        public ?AnalysisAxisRef $columnAxis,
        public StatisticsFilter $statisticsFilter,
        public PresentationConfig $presentation,
        public string $title,
        public ExplorerHospitalPopulationMode $hospitalPopulationMode = ExplorerHospitalPopulationMode::Participating,
    ) {
    }

    public function primaryMetricKey(): AnalysisMetricKey
    {
        return $this->visualMetricKey;
    }

    public function showsPercentOfTotal(): bool
    {
        return \in_array(AnalysisMetricKey::PercentOfTotal, $this->metricKeys, true);
    }

    public function hasColumnAxis(): bool
    {
        return $this->columnAxis instanceof AnalysisAxisRef;
    }

    public function withStatisticsFilter(StatisticsFilter $statisticsFilter): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    public function withAxes(AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    public function withMetrics(array $metricKeys, AnalysisMetricKey $visualMetricKey): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    public function withMetric(AnalysisMetricKey $metricKey): self
    {
        return $this->withMetrics([$metricKey], $metricKey);
    }

    public function withPresentation(PresentationConfig $presentation): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $presentation,
            title: $this->title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    public function withTitle(string $title): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    public function withDataSourceKey(AnalysisDataSourceKey $dataSourceKey): self
    {
        return new self(
            dataSourceKey: $dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }

    public function withHospitalPopulationMode(ExplorerHospitalPopulationMode $hospitalPopulationMode): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            rowAxis: $this->rowAxis,
            columnAxis: $this->columnAxis,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
            hospitalPopulationMode: $hospitalPopulationMode,
        );
    }
}
