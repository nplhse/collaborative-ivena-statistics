<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
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
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $timeGrain,
        public StatisticsFilter $statisticsFilter,
        public PresentationConfig $presentation,
        public string $title,
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

    public function withStatisticsFilter(StatisticsFilter $statisticsFilter): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
        );
    }

    public function withDimension(AnalysisDimensionKey $dimensionKey, ?AnalysisDimensionGrain $timeGrain): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
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
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
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
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $presentation,
            title: $this->title,
        );
    }

    public function withTitle(string $title): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $title,
        );
    }
}
