<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerColumnGrainResolver $columnGrainResolver,
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
        $dataSourceKey = AnalysisDataSourceKey::tryFrom($formData->dataSource) ?? AnalysisDataSourceKey::Allocations;
        $capabilities = $this->capabilitiesRegistry->capabilitiesFor(
            $dataSourceKey,
            $user instanceof User ? $user : null,
            $filter,
        );

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        if (!\in_array($rowAxis->dimensionKey, $capabilities->dimensions, true)) {
            $rowAxis = AnalysisDataSourceKey::Hospitals === $dataSourceKey
                ? AnalysisAxisRef::breakdown($capabilities->defaultDimension)
                : AnalysisAxisRef::time($capabilities->defaultTimeGrain);
        }

        $columnAxis = null;
        if (null !== $formData->columnDimension && '' !== $formData->columnDimension) {
            $columnDimension = AnalysisDimensionKey::tryFrom($formData->columnDimension);
            if ($columnDimension instanceof AnalysisDimensionKey) {
                $submittedColumnGrain = \is_string($formData->columnGrain)
                    ? AnalysisDimensionGrain::tryFrom($formData->columnGrain)
                    : null;
                $columnGrain = $this->columnGrainResolver->resolve(
                    $rowAxis,
                    $columnDimension,
                    $submittedColumnGrain,
                    $capabilities,
                );
                $candidate = $this->axisResolver->resolveFromStrings(
                    $formData->columnDimension,
                    $columnGrain->value,
                    $capabilities,
                );
                if ($capabilities->supportsColumnAxis($rowAxis, $candidate)) {
                    $columnAxis = $candidate;
                }
            }
        }

        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? $capabilities->defaultMetric;
        $previewConfig = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);
        $compatibleMetrics = $this->metricCapabilityPolicy->metricsForConfig($previewConfig);
        if (!\in_array($metric, $compatibleMetrics, true)) {
            $metric = $compatibleMetrics[0] ?? $capabilities->defaultMetric;
            $previewConfig = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);
            $compatibleMetrics = $this->metricCapabilityPolicy->metricsForConfig($previewConfig);
        }

        $isDistributionProfile = $metric->isDistributionProfile();

        $additionalTableMetrics = [];
        if (!$isDistributionProfile) {
            foreach ($formData->additionalTableMetrics as $value) {
                if ('' === $value) {
                    continue;
                }

                $metricKey = AnalysisMetricKey::tryFrom($value);
                if (!$metricKey instanceof AnalysisMetricKey
                    || $metricKey === $metric
                    || AnalysisMetricKey::PercentOfTotal === $metricKey) {
                    continue;
                }

                if (\in_array($metricKey, $compatibleMetrics, true)) {
                    $additionalTableMetrics[] = $metricKey->value;
                }
            }
        }

        $showPercentOfTotal = !$isDistributionProfile
            && $formData->showPercentOfTotal
            && $this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig);

        $allowedCharts = $capabilities->chartTypesFor($previewConfig);
        $chartType = $isDistributionProfile
            ? ChartPresentationType::BoxPlot
            : (ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig));
        if (!\in_array($chartType, $allowedCharts, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
        }

        $tableLayout = TableLayout::tryFrom($formData->tableLayout) ?? TableLayout::Flat;
        if ($isDistributionProfile || !$columnAxis instanceof AnalysisAxisRef) {
            $tableLayout = TableLayout::Flat;
        }

        $chartRowLimit = ExplorerChartRowLimit::fromValue($formData->chartRowLimit);
        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            $chartRowLimit = ExplorerChartRowLimit::All;
        }

        $hospitalPopulation = ExplorerHospitalPopulationMode::tryFrom($formData->hospitalPopulation)
            ?? ExplorerHospitalPopulationMode::Participating;

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dataSource: $dataSourceKey->value,
            rowDimension: $rowAxis->dimensionKey->value,
            rowGrain: $rowAxis->resolvedGrain()->value,
            columnDimension: $columnAxis?->dimensionKey->value,
            columnGrain: $columnAxis?->resolvedGrain()->value,
            metric: $metric->value,
            showPercentOfTotal: $showPercentOfTotal,
            chartType: $chartType->value,
            tableLayout: $tableLayout->value,
            chartRowLimit: $chartRowLimit->value,
            hospitalPopulation: $hospitalPopulation->value,
            additionalTableMetrics: $additionalTableMetrics,
        );
    }
}
