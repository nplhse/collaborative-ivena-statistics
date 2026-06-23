<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AnalysisViewConfigValidator
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private Security $security,
    ) {
    }

    public function validate(AnalysisViewConfig $config): void
    {
        $capabilities = $this->capabilitiesFor($config);

        if (!$capabilities->supports($config)) {
            throw new InvalidExplorerConfigException($this->buildMessageKey($config, $capabilities), $this->buildParameters($config));
        }

        if ($this->hasRateAndDistribution($config->metricKeys)) {
            throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.incompatible_metrics', $this->buildParameters($config));
        }

        $allowedMetrics = $this->capabilitiesProvider->metricsForConfig($config);
        foreach ($config->metricKeys as $metricKey) {
            if (!\in_array($metricKey, $allowedMetrics, true)) {
                throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.unsupported_metric', $this->buildParameters($config));
            }
        }

        if (!$this->metricCapabilityPolicy->isChartable($config->visualMetricKey)) {
            throw new InvalidExplorerConfigException('stats.analysis_explorer.validation.unsupported_metric', $this->buildParameters($config));
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
        if (!$capabilities->supportsAxis($config->rowAxis)) {
            return 'stats.analysis_explorer.validation.unsupported_dimension';
        }

        if ($config->columnAxis instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef
            && !$capabilities->supportsColumnAxis($config->rowAxis, $config->columnAxis)) {
            return 'stats.analysis_explorer.validation.unsupported_dimension';
        }

        foreach ($config->metricKeys as $metricKey) {
            if (!\in_array($metricKey, $capabilities->metrics, true)) {
                return 'stats.analysis_explorer.validation.unsupported_metric';
            }
        }

        $allowedCharts = $capabilities->chartTypesFor($config);
        if (!\in_array($config->presentation->chartType, $allowedCharts, true)) {
            return 'stats.analysis_explorer.validation.unsupported_chart';
        }

        return 'stats.analysis_explorer.validation.invalid';
    }

    /**
     * @return array<string, string|int|float>
     */
    private function buildParameters(AnalysisViewConfig $config): array
    {
        return [
            'rows' => $config->rowAxis->dimensionKey->value,
            'columns' => $config->columnAxis?->dimensionKey->value ?? '',
            'metric' => $config->visualMetricKey->value,
            'chart' => $config->presentation->chartType->value,
            'grain' => $config->rowAxis->resolvedGrain()->value,
        ];
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function hasRateAndDistribution(array $metricKeys): bool
    {
        $hasRate = false;
        $hasDistribution = false;
        foreach ($metricKeys as $metricKey) {
            if (AnalysisMetricKey::PercentOfTotal === $metricKey) {
                $hasDistribution = true;
            }
            if ('rate' === $metricKey->metricCategory()->value) {
                $hasRate = true;
            }
        }

        return $hasRate && $hasDistribution;
    }
}
