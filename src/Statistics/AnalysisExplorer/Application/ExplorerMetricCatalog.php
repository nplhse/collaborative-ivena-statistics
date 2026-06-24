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
    /** @var array<string, array<string, ExplorerMetricDefinition>> */
    private array $metricsBySource = [];

    public function __construct(
        private readonly MetricRegistry $metricRegistry,
    ) {
    }

    public function get(AnalysisMetricKey $key, AnalysisDataSourceKey $dataSourceKey): ExplorerMetricDefinition
    {
        return $this->allForDataSource($dataSourceKey)[$key->value]
            ?? throw UnknownExplorerMetricException::forKey($key->value);
    }

    public function has(AnalysisMetricKey $key, AnalysisDataSourceKey $dataSourceKey): bool
    {
        return isset($this->allForDataSource($dataSourceKey)[$key->value]);
    }

    /**
     * @return array<string, ExplorerMetricDefinition>
     */
    public function allForDataSource(AnalysisDataSourceKey $dataSourceKey): array
    {
        if (isset($this->metricsBySource[$dataSourceKey->value])) {
            return $this->metricsBySource[$dataSourceKey->value];
        }

        $catalog = match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => AnalysisMetricKey::allocationsCatalog(),
            AnalysisDataSourceKey::Hospitals => AnalysisMetricKey::hospitalsCatalog(),
        };

        $enabledSet = match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => array_flip(array_map(
                static fn (AnalysisMetricKey $key): string => $key->value,
                array_merge(AnalysisMetricKey::enabledAllocationsCatalog(), [AnalysisMetricKey::PercentOfTotal]),
            )),
            AnalysisDataSourceKey::Hospitals => array_flip(array_map(
                static fn (AnalysisMetricKey $key): string => $key->value,
                array_merge(AnalysisMetricKey::enabledHospitalsCatalog(), [AnalysisMetricKey::PercentOfTotal]),
            )),
        };

        $metrics = [];
        foreach ($catalog as $explorerKey) {
            if ($explorerKey->isDistributionProfile()) {
                $metrics[$explorerKey->value] = new ExplorerMetricDefinition(
                    explorerKey: $explorerKey,
                    gaDefinition: null,
                    enabled: isset($enabledSet[$explorerKey->value]),
                );
                continue;
            }

            $registryKey = $explorerKey->registryKey();
            if (!$this->metricRegistry->has($registryKey)) {
                continue;
            }

            $metrics[$explorerKey->value] = new ExplorerMetricDefinition(
                explorerKey: $explorerKey,
                gaDefinition: $this->metricRegistry->get($registryKey),
                enabled: isset($enabledSet[$explorerKey->value]),
            );
        }

        return $this->metricsBySource[$dataSourceKey->value] = $metrics;
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
     * @return list<string>
     */
    public function metricGroupOrderFor(AnalysisDataSourceKey $dataSourceKey): array
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.clinical_rates',
                'stats.analysis_explorer.metric_group.shares',
                'stats.analysis_explorer.metric_group.transport_times',
            ],
            AnalysisDataSourceKey::Hospitals => [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.beds',
                'stats.analysis_explorer.metric_group.allocations',
                'stats.analysis_explorer.metric_group.transport_times',
            ],
        };
    }
}
