<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AnalysisViewConfigNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private ExplorerTitleFactory $titleFactory,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private ExplorerTableLayoutResolver $tableLayoutResolver,
        private Security $security,
    ) {
    }

    public function normalize(AnalysisViewConfig $config): AnalysisViewConfig
    {
        $capabilities = $this->capabilitiesFor($config);

        $rowAxis = $capabilities->supportsAxis($config->rowAxis)
            ? $this->axisResolver->resolve($config->rowAxis, $capabilities)
            : AnalysisAxisRef::time($capabilities->defaultTimeGrain);

        $columnAxis = $config->columnAxis;
        if ($columnAxis instanceof AnalysisAxisRef) {
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

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = \in_array($config->presentation->chartType, $allowedChartTypes, true)
            ? $config->presentation->chartType
            : $capabilities->defaultChartTypeFor($previewConfig);

        $tableLayout = $config->presentation->tableLayout;
        if (!$columnAxis instanceof AnalysisAxisRef && TableLayout::Flat !== $tableLayout) {
            $tableLayout = TableLayout::Flat;
        } elseif ($columnAxis instanceof AnalysisAxisRef && TableLayout::Flat === $tableLayout) {
            $tableLayout = $this->tableLayoutResolver->resolveForConfig($previewConfig);
        }

        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: $config->statisticsFilter,
            presentation: new PresentationConfig(
                chartType: $chartType,
                mode: $config->presentation->mode,
                tableLayout: $tableLayout,
                chartRowLimit: $config->presentation->chartRowLimit,
            ),
            title: $this->titleFactory->titleForAxes($rowAxis, $columnAxis),
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
        $origCol = $original->columnAxis?->dimensionKey->value ?? '';
        $normCol = $normalized->columnAxis?->dimensionKey->value ?? '';
        if ($origCol !== $normCol) {
            $warnings[] = 'columns';
        }
        if ($original->presentation->chartType !== $normalized->presentation->chartType) {
            $warnings[] = 'chartType';
        }
        if ($original->metricKeys !== $normalized->metricKeys) {
            $warnings[] = 'metrics';
        }
        if ($original->visualMetricKey !== $normalized->visualMetricKey) {
            $warnings[] = 'visualMetric';
        }

        return $warnings;
    }

    private function capabilitiesFor(AnalysisViewConfig $config): \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities
    {
        $user = $this->security->getUser();

        return $this->capabilitiesProvider->capabilitiesFor(
            $user instanceof User ? $user : null,
            $config->statisticsFilter,
        );
    }
}
