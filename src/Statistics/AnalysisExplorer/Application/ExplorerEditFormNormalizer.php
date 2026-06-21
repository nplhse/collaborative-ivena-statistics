<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
    ) {
    }

    public function normalize(ExplorerEditFormData $formData): ExplorerEditFormData
    {
        $capabilities = $this->capabilitiesProvider->capabilities();

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        if (!\in_array($rowAxis->dimensionKey, $capabilities->dimensions, true)) {
            $rowAxis = AnalysisAxisRef::time($capabilities->defaultTimeGrain);
        }

        $columnAxis = null;
        if (null !== $formData->columnDimension && '' !== $formData->columnDimension) {
            $candidate = $this->axisResolver->resolveFromStrings(
                $formData->columnDimension,
                $formData->columnGrain,
                $capabilities,
            );
            if ($capabilities->supportsColumnAxis($rowAxis, $candidate)) {
                $columnAxis = $candidate;
            }
        }

        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? $capabilities->defaultMetric;
        if (!\in_array($metric, $capabilities->primaryMetrics, true)) {
            $metric = $capabilities->defaultMetric;
        }

        $previewConfig = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);
        $showPercentOfTotal = $formData->showPercentOfTotal
            && $this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig);

        $allowedCharts = $capabilities->chartTypesFor($previewConfig);
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig);
        if (!\in_array($chartType, $allowedCharts, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
        }

        $tableLayout = TableLayout::tryFrom($formData->tableLayout) ?? TableLayout::Flat;
        if (!$columnAxis instanceof AnalysisAxisRef) {
            $tableLayout = TableLayout::Flat;
        }

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            rowDimension: $rowAxis->dimensionKey->value,
            rowGrain: $rowAxis->resolvedGrain()->value,
            columnDimension: $columnAxis?->dimensionKey->value,
            columnGrain: $columnAxis?->resolvedGrain()->value,
            metric: $metric->value,
            showPercentOfTotal: $showPercentOfTotal,
            chartType: $chartType->value,
            tableLayout: $tableLayout->value,
        );
    }
}
