<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
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

        $chartType = \in_array($config->presentation->chartType, $capabilities->chartTypes, true)
            ? $config->presentation->chartType
            : $capabilities->defaultChartType;

        $timeGrain = $this->resolveTimeGrain($dimensionKey, $config->timeGrain, $capabilities);

        $title = $this->titleFactory->titleFor($dimensionKey);

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
            title: $title,
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

    private function resolveTimeGrain(
        AnalysisDimensionKey $dimensionKey,
        ?AnalysisDimensionGrain $timeGrain,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
    ): ?AnalysisDimensionGrain {
        if (AnalysisDimensionKey::Time !== $dimensionKey) {
            return null;
        }

        $allowed = $capabilities->timeGrainsFor($dimensionKey);
        if ($timeGrain instanceof AnalysisDimensionGrain && \in_array($timeGrain, $allowed, true)) {
            return $timeGrain;
        }

        return $capabilities->defaultTimeGrain;
    }
}
