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

        if (!\in_array($config->presentation->chartType, $capabilities->chartTypes, true)) {
            return sprintf('Unsupported chart type "%s".', $config->presentation->chartType->value);
        }

        if (AnalysisDimensionKey::Time === $config->dimensionKey) {
            if (!$config->timeGrain instanceof AnalysisDimensionGrain) {
                return 'Time dimension requires a grain.';
            }
            if (!\in_array($config->timeGrain, $capabilities->timeGrains, true)) {
                return sprintf('Unsupported grain "%s" for time dimension.', $config->timeGrain->value);
            }
        } elseif ($config->timeGrain instanceof AnalysisDimensionGrain) {
            return sprintf('Grain is not allowed for dimension "%s".', $config->dimensionKey->value);
        }

        return 'Invalid analysis configuration.';
    }
}
