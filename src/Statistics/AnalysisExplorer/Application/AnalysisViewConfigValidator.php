<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;

final readonly class AnalysisViewConfigValidator
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
    ) {
    }

    public function validate(AnalysisViewConfig $config): void
    {
        $capabilities = $this->capabilitiesProvider->capabilities();

        if (!$capabilities->supports($config)) {
            throw new InvalidExplorerConfigException($this->buildMessage($config, $capabilities));
        }
    }

    private function buildMessage(AnalysisViewConfig $config, DataSourceCapabilities $capabilities): string
    {
        if (!\in_array($config->dimensionKey, $capabilities->dimensions, true)) {
            return sprintf('Unsupported dimension "%s".', $config->dimensionKey->value);
        }

        if (!\in_array($config->metricKey, $capabilities->metrics, true)) {
            return sprintf('Unsupported metric "%s".', $config->metricKey->value);
        }

        $allowedCharts = $capabilities->chartTypesFor($config);
        if (!\in_array($config->presentation->chartType, $allowedCharts, true)) {
            return sprintf('Unsupported chart type "%s".', $config->presentation->chartType->value);
        }

        $grain = $config->timeGrain;
        if (!$grain instanceof AnalysisDimensionGrain) {
            return 'A grain is required.';
        }

        $allowedGrains = $capabilities->timeGrainsFor($config->dimensionKey);
        if (!\in_array($grain, $allowedGrains, true)) {
            return sprintf('Unsupported grain "%s" for dimension "%s".', $grain->value, $config->dimensionKey->value);
        }

        if (AnalysisDimensionKey::Time === $config->dimensionKey && AnalysisDimensionGrain::Total === $grain) {
            return 'Total grain is not allowed for the time dimension.';
        }

        return 'Invalid analysis configuration.';
    }
}
