<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\PresentationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final readonly class AnalysisViewConfigNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private ExplorerTitleFactory $titleFactory,
        private AnalysisDimensionGrainResolver $grainResolver,
        private ExplorerConfigPreviewFactory $previewFactory,
    ) {
    }

    public function normalize(AnalysisViewConfig $config): AnalysisViewConfig
    {
        $capabilities = $this->capabilitiesProvider->capabilities();

        $dimensionKey = \in_array($config->dimensionKey, $capabilities->dimensions, true)
            ? $config->dimensionKey
            : $capabilities->defaultDimension;

        $metricKey = \in_array($config->metricKey, $capabilities->metrics, true)
            ? $config->metricKey
            : $capabilities->defaultMetric;

        $timeGrain = $this->grainResolver->resolveFromEnum($dimensionKey, $config->timeGrain, $capabilities);

        $previewConfig = $this->previewFactory->fromConfig($capabilities, $dimensionKey, $metricKey, $timeGrain, $config);

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = \in_array($config->presentation->chartType, $allowedChartTypes, true)
            ? $config->presentation->chartType
            : $capabilities->defaultChartTypeFor($previewConfig);

        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $metricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $config->statisticsFilter,
            presentation: new PresentationConfig(
                chartType: $chartType,
                mode: PresentationMode::Chart,
            ),
            title: $this->titleFactory->titleFor($dimensionKey, $timeGrain),
        );
    }

    /**
     * @return list<string>
     */
    public function diffWarnings(AnalysisViewConfig $original, AnalysisViewConfig $normalized): array
    {
        $warnings = [];

        if ($original->dimensionKey !== $normalized->dimensionKey) {
            $warnings[] = 'dimension';
        }
        if ($original->timeGrain !== $normalized->timeGrain) {
            $warnings[] = 'grain';
        }
        if ($original->presentation->chartType !== $normalized->presentation->chartType) {
            $warnings[] = 'chartType';
        }

        return $warnings;
    }
}
