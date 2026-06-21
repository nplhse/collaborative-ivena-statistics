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
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public AnalysisMetricKey $metricKey,
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $timeGrain,
        public StatisticsFilter $statisticsFilter,
        public PresentationConfig $presentation,
        public string $title,
    ) {
    }

    public function withStatisticsFilter(StatisticsFilter $statisticsFilter): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $this->metricKey,
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
            metricKey: $this->metricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
        );
    }

    public function withMetric(AnalysisMetricKey $metricKey): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $metricKey,
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
        );
    }

    public function withPresentation(PresentationConfig $presentation): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $this->metricKey,
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
            metricKey: $this->metricKey,
            dimensionKey: $this->dimensionKey,
            timeGrain: $this->timeGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $this->presentation,
            title: $title,
        );
    }
}
