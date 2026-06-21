<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
    ) {
    }

    public function normalize(ExplorerEditFormData $formData): ExplorerEditFormData
    {
        $capabilities = $this->capabilitiesProvider->capabilities();

        $dimension = AnalysisDimensionKey::tryFrom($formData->dimension) ?? $capabilities->defaultDimension;
        if (!\in_array($dimension, $capabilities->dimensions, true)) {
            $dimension = $capabilities->defaultDimension;
        }

        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? $capabilities->defaultMetric;
        if (!\in_array($metric, $capabilities->metrics, true)) {
            $metric = $capabilities->defaultMetric;
        }

        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartType;
        if (!\in_array($chartType, $capabilities->chartTypes, true)) {
            $chartType = $capabilities->defaultChartType;
        }

        $timeGrain = $this->resolveTimeGrainString($dimension, $formData->timeGrain, $capabilities);

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dimension: $dimension->value,
            metric: $metric->value,
            timeGrain: $timeGrain,
            chartType: $chartType->value,
        );
    }

    private function resolveTimeGrainString(
        AnalysisDimensionKey $dimension,
        ?string $timeGrain,
        DataSourceCapabilities $capabilities,
    ): ?string {
        if (AnalysisDimensionKey::Time !== $dimension) {
            return null;
        }

        $grain = null !== $timeGrain ? AnalysisDimensionGrain::tryFrom($timeGrain) : null;
        $allowed = $capabilities->timeGrainsFor($dimension);

        if ($grain instanceof AnalysisDimensionGrain && \in_array($grain, $allowed, true)) {
            return $grain->value;
        }

        return $capabilities->defaultTimeGrain->value;
    }
}
