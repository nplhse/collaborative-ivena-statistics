<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class AnalysisViewConfig
{
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public AnalysisMetricKey $metricKey,
        public AnalysisDimensionGrain $dimensionGrain,
        public StatisticsFilter $statisticsFilter,
        public PresentationConfig $presentation,
        public string $title,
    ) {
    }

    public function duplicate(): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $this->metricKey,
            dimensionGrain: $this->dimensionGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: new PresentationConfig(
                chartType: $this->presentation->chartType,
            ),
            title: $this->title,
        );
    }

    public function withStatisticsFilter(StatisticsFilter $statisticsFilter): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $this->metricKey,
            dimensionGrain: $this->dimensionGrain,
            statisticsFilter: $statisticsFilter,
            presentation: $this->presentation,
            title: $this->title,
        );
    }

    public function withDimensionGrain(AnalysisDimensionGrain $dimensionGrain): self
    {
        return new self(
            dataSourceKey: $this->dataSourceKey,
            metricKey: $this->metricKey,
            dimensionGrain: $dimensionGrain,
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
            dimensionGrain: $this->dimensionGrain,
            statisticsFilter: $this->statisticsFilter,
            presentation: $presentation,
            title: $this->title,
        );
    }
}
