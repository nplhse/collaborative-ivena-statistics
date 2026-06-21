<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\PresentationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final readonly class AnalysisViewConfigNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private ExplorerTitleFactory $titleFactory,
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

        $timeGrain = $this->resolveTimeGrain($dimensionKey, $config->timeGrain, $capabilities);

        $previewConfig = new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $metricKey,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            statisticsFilter: $config->statisticsFilter,
            presentation: $config->presentation,
            title: $config->title,
        );

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = \in_array($config->presentation->chartType, $allowedChartTypes, true)
            ? $config->presentation->chartType
            : $capabilities->defaultChartTypeFor($previewConfig);

        $normalized = new AnalysisViewConfig(
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

        return $normalized;
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

    private function resolveTimeGrain(
        AnalysisDimensionKey $dimensionKey,
        ?AnalysisDimensionGrain $timeGrain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        $allowed = $capabilities->timeGrainsFor($dimensionKey);

        if ($timeGrain instanceof AnalysisDimensionGrain && \in_array($timeGrain, $allowed, true)) {
            return $timeGrain;
        }

        return match ($dimensionKey) {
            AnalysisDimensionKey::Time => $capabilities->defaultTimeGrain,
            AnalysisDimensionKey::Gender, AnalysisDimensionKey::Urgency => AnalysisDimensionGrain::Total,
        };
    }
}
