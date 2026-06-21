<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AnalysisViewConfigValidator
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private Security $security,
    ) {
    }

    public function validate(AnalysisViewConfig $config): void
    {
        $capabilities = $this->capabilitiesFor($config);

        if (!$capabilities->supports($config)) {
            throw new InvalidExplorerConfigException($this->buildMessageKey($config, $capabilities), $this->buildParameters($config));
        }
    }

    public function capabilitiesFor(AnalysisViewConfig $config): DataSourceCapabilities
    {
        $user = $this->security->getUser();

        return $this->capabilitiesProvider->capabilitiesFor(
            $user instanceof User ? $user : null,
            $config->statisticsFilter,
        );
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

        if ($config->dimensionKey->isTemporalPrimary() && AnalysisDimensionGrain::Total === $grain) {
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
