<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private Security $security,
    ) {
    }

    public function normalize(ExplorerEditFormData $formData): ExplorerEditFormData
    {
        $user = $this->security->getUser();
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user instanceof User ? $user : null,
        );
        $capabilities = $this->capabilitiesProvider->capabilitiesFor(
            $user instanceof User ? $user : null,
            $filter,
        );

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
