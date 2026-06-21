<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private AnalysisDimensionGrainResolver $grainResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
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
        if (!\in_array($metric, $capabilities->primaryMetrics, true)) {
            $metric = $capabilities->defaultMetric;
        }

        $timeGrain = $this->grainResolver->resolveFromString($dimension, $formData->timeGrain, $capabilities);

        $previewConfig = $this->previewFactory->fromFormData($capabilities, $dimension, $metric, $timeGrain, $formData);
        $showPercentOfTotal = $formData->showPercentOfTotal
            && $this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig);

        $allowedCharts = $capabilities->chartTypesFor($previewConfig);
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig);
        if (!\in_array($chartType, $allowedCharts, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
        }

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dimension: $dimension->value,
            metric: $metric->value,
            showPercentOfTotal: $showPercentOfTotal,
            timeGrain: $timeGrain->value,
            chartType: $chartType->value,
        );
    }
}
