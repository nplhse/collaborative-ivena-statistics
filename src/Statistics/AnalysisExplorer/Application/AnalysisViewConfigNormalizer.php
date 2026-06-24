<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AnalysisViewConfigNormalizer
{
    public function __construct(
        private DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private ExplorerTitleFactory $titleFactory,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private ExplorerTableLayoutResolver $tableLayoutResolver,
        private ExplorerAnalysisFilterPolicy $analysisFilterPolicy,
        private Security $security,
    ) {
    }

    public function normalize(AnalysisViewConfig $config): AnalysisViewConfig
    {
        $capabilities = $this->capabilitiesFor($config);
        $requestedDistributionProfile = $config->visualMetricKey->isDistributionProfile()
            ? $config->visualMetricKey
            : null;

        $rowAxis = $capabilities->supportsAxis($config->rowAxis)
            ? $this->axisResolver->resolve($config->rowAxis, $capabilities)
            : ('hospitals' === $config->dataSourceKey->value
                ? \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef::breakdown($capabilities->defaultDimension)
                : \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef::time($capabilities->defaultTimeGrain));

        $columnAxis = $config->columnAxis;
        if ($columnAxis instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef) {
            if (!$capabilities->supportsColumnAxis($rowAxis, $columnAxis)) {
                $columnAxis = null;
            } else {
                $columnAxis = $this->axisResolver->resolve($columnAxis, $capabilities);
            }
        }

        $visualMetricKey = \in_array($config->visualMetricKey, $capabilities->primaryMetrics, true)
            ? $config->visualMetricKey
            : $capabilities->defaultMetric;

        $previewConfig = $this->previewFactory->fromConfig(
            $capabilities,
            $rowAxis,
            $columnAxis,
            $visualMetricKey,
            $config,
        );

        $metricKeys = $this->metricCapabilityPolicy->normalizeMetricKeys($previewConfig->metricKeys, $previewConfig);
        if (!\in_array($visualMetricKey, $metricKeys, true)) {
            $visualMetricKey = $metricKeys[0];
        }

        $previewConfig = $previewConfig->withMetrics($metricKeys, $visualMetricKey);

        if ($requestedDistributionProfile instanceof \App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey) {
            $visualMetricKey = $requestedDistributionProfile;
            $metricKeys = [$requestedDistributionProfile];
            $chartType = ChartPresentationType::BoxPlot;
        } elseif ($visualMetricKey->isDistributionProfile()) {
            $metricKeys = [$visualMetricKey];
            $chartType = ChartPresentationType::BoxPlot;
        } else {
            $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
            $chartType = \in_array($config->presentation->chartType, $allowedChartTypes, true)
                ? $config->presentation->chartType
                : $capabilities->defaultChartTypeFor($previewConfig);
        }

        $tableLayout = $config->presentation->tableLayout;
        if ($visualMetricKey->isDistributionProfile() || $requestedDistributionProfile instanceof \App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey) {
            $tableLayout = \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Flat;
        } elseif (!$columnAxis instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef && \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Flat !== $tableLayout) {
            $tableLayout = \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Flat;
        } elseif ($columnAxis instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef && \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Flat === $tableLayout) {
            $tableLayout = $this->tableLayoutResolver->resolveForConfig($previewConfig);
        }

        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: $config->statisticsFilter,
            presentation: new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(
                chartType: $chartType,
                mode: $config->presentation->mode,
                tableLayout: $tableLayout,
                chartRowLimit: $config->presentation->chartRowLimit,
            ),
            title: $this->titleFactory->titleForAxes($rowAxis, $columnAxis),
            hospitalPopulationMode: $config->hospitalPopulationMode,
            filters: $this->analysisFilterPolicy->sanitizeForConfig($config, $config->filters),
        );
    }

    /**
     * @return list<string>
     */
    public function diffWarnings(AnalysisViewConfig $original, AnalysisViewConfig $normalized): array
    {
        $warnings = [];

        if ($original->rowAxis->dimensionKey !== $normalized->rowAxis->dimensionKey
            || $original->rowAxis->resolvedGrain() !== $normalized->rowAxis->resolvedGrain()) {
            $warnings[] = 'rows';
        }

        $originalColumn = $original->columnAxis?->dimensionKey->value;
        $normalizedColumn = $normalized->columnAxis?->dimensionKey->value;
        if ($originalColumn !== $normalizedColumn) {
            $warnings[] = 'columns';
        }

        if ($original->visualMetricKey !== $normalized->visualMetricKey) {
            $warnings[] = 'visualMetric';
        }

        return $warnings;
    }

    private function capabilitiesFor(AnalysisViewConfig $config): DataSourceCapabilities
    {
        $user = $this->security->getUser();

        return $this->capabilitiesRegistry->capabilitiesFor(
            $config->dataSourceKey,
            $user instanceof User ? $user : null,
            $config->statisticsFilter,
        );
    }
}
