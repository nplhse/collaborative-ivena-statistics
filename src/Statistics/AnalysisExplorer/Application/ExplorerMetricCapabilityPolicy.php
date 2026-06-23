<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;

final readonly class ExplorerMetricCapabilityPolicy
{
    public function __construct(
        private ExplorerMetricCatalog $metricCatalog,
        private ExplorerQueryMapperRegistry $queryMapperRegistry,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    public function metricsForConfig(AnalysisViewConfig $config): array
    {
        $available = [];
        foreach ($this->metricCatalog->enabledKeysForDataSource($config->dataSourceKey) as $metricKey) {
            if ($this->isMetricAllowedForConfig($metricKey, $config)) {
                $available[] = $metricKey;
            }
        }

        return $available;
    }

    public function canShowPercentOfTotal(AnalysisViewConfig $config): bool
    {
        if (!\in_array($config->visualMetricKey, [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::HospitalCount], true)) {
            return false;
        }

        if ($this->hasRateMetric($config->metricKeys)) {
            return false;
        }

        return $this->isMetricAllowedForConfig(AnalysisMetricKey::PercentOfTotal, $config);
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     *
     * @return list<AnalysisMetricKey>
     */
    public function normalizeMetricKeys(array $metricKeys, AnalysisViewConfig $config): array
    {
        $normalized = [];
        foreach ($metricKeys as $metricKey) {
            if (!$this->metricCatalog->has($metricKey, $config->dataSourceKey)) {
                continue;
            }

            $definition = $this->metricCatalog->get($metricKey, $config->dataSourceKey);
            if (!$definition->enabled) {
                continue;
            }

            if (!$this->isMetricAllowedForConfig($metricKey, $config)) {
                continue;
            }

            $normalized[] = $metricKey;
        }

        $defaultMetric = AnalysisMetricKey::defaultFor($config->dataSourceKey);
        if ([] === $normalized) {
            return [$defaultMetric];
        }

        if (\in_array(AnalysisMetricKey::PercentOfTotal, $normalized, true)
            && !\in_array($defaultMetric, $normalized, true)) {
            array_unshift($normalized, $defaultMetric);
        }

        if ($this->hasRateMetric($normalized) && \in_array(AnalysisMetricKey::PercentOfTotal, $normalized, true)) {
            $normalized = array_values(array_filter(
                $normalized,
                static fn (AnalysisMetricKey $key): bool => AnalysisMetricKey::PercentOfTotal !== $key,
            ));
        }

        return array_values(array_unique($normalized, \SORT_REGULAR));
    }

    public function isChartable(AnalysisMetricKey $metricKey, AnalysisDataSourceKey $dataSourceKey): bool
    {
        if (!$this->metricCatalog->has($metricKey, $dataSourceKey)) {
            return false;
        }

        if ($metricKey->isDistributionProfile()) {
            return $this->metricCatalog->get($metricKey, $dataSourceKey)->enabled;
        }

        return $this->metricCatalog->get($metricKey, $dataSourceKey)->isChartable();
    }

    private function isMetricAllowedForConfig(AnalysisMetricKey $metricKey, AnalysisViewConfig $config): bool
    {
        if ($metricKey->isDistributionProfile()) {
            return $this->profileRegistry->isAllowedForConfig($config);
        }

        if (AnalysisMetricKey::PercentOfTotal === $metricKey) {
            $defaultMetric = AnalysisMetricKey::defaultFor($config->dataSourceKey);

            return $defaultMetric === $config->visualMetricKey
                && !$this->hasRateMetric($config->metricKeys);
        }

        if (AnalysisMetricKey::defaultFor($config->dataSourceKey) === $metricKey) {
            return true;
        }

        $gaQuery = $this->buildCompatibilityQuery($config, [$metricKey]);
        foreach ($this->metricCompatibilityChecker->listAvailability($gaQuery) as $item) {
            if ($item['metric']->key === $metricKey->registryKey()) {
                return $item['allowed'];
            }
        }

        return false;
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function buildCompatibilityQuery(AnalysisViewConfig $config, array $metricKeys): \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery
    {
        $query = new AnalysisQuery(
            dataSourceKey: $config->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $metricKeys[0],
            rowAxis: $config->rowAxis,
            columnAxis: $config->columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            hospitalPopulationMode: $config->hospitalPopulationMode,
        );

        return $this->queryMapperRegistry->map($query);
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function hasRateMetric(array $metricKeys): bool
    {
        return array_any($metricKeys, fn (AnalysisMetricKey $metricKey): bool => 'rate' === $metricKey->metricCategory()->value);
    }
}
