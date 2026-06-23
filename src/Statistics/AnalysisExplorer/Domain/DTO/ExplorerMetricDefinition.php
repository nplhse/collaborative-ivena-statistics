<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerMetricCategory;
use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;

final readonly class ExplorerMetricDefinition
{
    public function __construct(
        public AnalysisMetricKey $explorerKey,
        public ?MetricDefinition $gaDefinition,
        public bool $enabled,
    ) {
    }

    public function registryKey(): string
    {
        return $this->explorerKey->registryKey();
    }

    public function metricCategory(): ExplorerMetricCategory
    {
        return $this->explorerKey->metricCategory();
    }

    public function defaultFormat(): MetricFormat
    {
        if (!$this->gaDefinition instanceof MetricDefinition) {
            return MetricFormat::Decimal;
        }

        return $this->gaDefinition->defaultFormat;
    }

    public function isChartable(): bool
    {
        return $this->enabled && $this->explorerKey->isChartable();
    }
}
