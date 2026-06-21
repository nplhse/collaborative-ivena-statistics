<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricDefinition;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnknownExplorerMetricException;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final class ExplorerMetricCatalog
{
    /** @var array<string, ExplorerMetricDefinition>|null */
    private ?array $allocationsMetrics = null;

    public function __construct(
        private readonly MetricRegistry $metricRegistry,
    ) {
    }

    public function get(AnalysisMetricKey $key): ExplorerMetricDefinition
    {
        return $this->allForDataSource(AnalysisDataSourceKey::Allocations)[$key->value]
            ?? throw UnknownExplorerMetricException::forKey($key->value);
    }

    public function has(AnalysisMetricKey $key): bool
    {
        return isset($this->allForDataSource(AnalysisDataSourceKey::Allocations)[$key->value]);
    }

    /**
     * @return array<string, ExplorerMetricDefinition>
     */
    public function allForDataSource(AnalysisDataSourceKey $dataSourceKey): array
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => $this->allocationsMetrics(),
        };
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    public function enabledKeysForDataSource(AnalysisDataSourceKey $dataSourceKey): array
    {
        $keys = [];
        foreach ($this->allForDataSource($dataSourceKey) as $definition) {
            if ($definition->enabled) {
                $keys[] = $definition->explorerKey;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, ExplorerMetricDefinition>
     */
    private function allocationsMetrics(): array
    {
        if (null !== $this->allocationsMetrics) {
            return $this->allocationsMetrics;
        }

        $metrics = [];
        foreach (AnalysisMetricKey::allocationsCatalog() as $explorerKey) {
            $registryKey = $explorerKey->registryKey();
            if (!$this->metricRegistry->has($registryKey)) {
                continue;
            }

            $metrics[$explorerKey->value] = new ExplorerMetricDefinition(
                explorerKey: $explorerKey,
                gaDefinition: $this->metricRegistry->get($registryKey),
                enabled: $explorerKey->isEnabledInStepOne(),
            );
        }

        return $this->allocationsMetrics = $metrics;
    }
}
