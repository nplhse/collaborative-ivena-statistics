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
            throw new InvalidExplorerConfigException($this->buildMessageKey($config, $capabilities), $this->buildParameters($config));
        }
    }

    private function buildMessageKey(AnalysisViewConfig $config, DataSourceCapabilities $capabilities): string
    {
        if (!\in_array($config->dimensionKey, $capabilities->dimensions, true)) {
            return 'stats.analysis_explorer.validation.unsupported_dimension';
        }

        if (!\in_array($config->metricKey, $capabilities->metrics, true)) {
            return 'stats.analysis_explorer.validation.unsupported_metric';
        }

        $allowedCharts = $capabilities->chartTypesFor($config);
        if (!\in_array($config->presentation->chartType, $allowedCharts, true)) {
            return 'stats.analysis_explorer.validation.unsupported_chart';
        }

        $grain = $config->timeGrain;
        if (!$grain instanceof AnalysisDimensionGrain) {
            return 'stats.analysis_explorer.validation.grain_required';
        }

        $allowedGrains = $capabilities->timeGrainsFor($config->dimensionKey);
        if (!\in_array($grain, $allowedGrains, true)) {
            return 'stats.analysis_explorer.validation.unsupported_grain';
        }

        if (AnalysisDimensionKey::Time === $config->dimensionKey && AnalysisDimensionGrain::Total === $grain) {
            return 'stats.analysis_explorer.validation.total_grain_for_time';
        }

        return 'stats.analysis_explorer.validation.invalid';
    }

    /**
     * @return array<string, string|int|float>
     */
    private function buildParameters(AnalysisViewConfig $config): array
    {
        return [
            'dimension' => $config->dimensionKey->value,
            'metric' => $config->metricKey->value,
            'chart' => $config->presentation->chartType->value,
            'grain' => $config->timeGrain instanceof AnalysisDimensionGrain ? $config->timeGrain->value : '',
        ];
    }
}
