<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerMetricCategory;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;

final readonly class ExplorerMetricCapabilityPolicy
{
    public function __construct(
        private ExplorerMetricCatalog $metricCatalog,
        private ExplorerAllocationQueryMapper $queryMapper,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
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
        if ($config->dimensionKey->isTemporalPrimary()) {
            return false;
        }

        if (!$config->timeGrain instanceof AnalysisDimensionGrain || AnalysisDimensionGrain::Total !== $config->timeGrain) {
            return false;
        }

        if (AnalysisMetricKey::AllocationCount !== $config->visualMetricKey) {
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
            if (!$this->metricCatalog->has($metricKey) || !$this->metricCatalog->get($metricKey)->enabled) {
                continue;
            }

            if (!$this->isMetricAllowedForConfig($metricKey, $config)) {
                continue;
            }

            $normalized[] = $metricKey;
        }

        if ([] === $normalized) {
            return [AnalysisMetricKey::AllocationCount];
        }

        if (\in_array(AnalysisMetricKey::PercentOfTotal, $normalized, true)
            && !\in_array(AnalysisMetricKey::AllocationCount, $normalized, true)) {
            array_unshift($normalized, AnalysisMetricKey::AllocationCount);
        }

        if ($this->hasRateMetric($normalized) && \in_array(AnalysisMetricKey::PercentOfTotal, $normalized, true)) {
            $normalized = array_values(array_filter(
                $normalized,
                static fn (AnalysisMetricKey $key): bool => AnalysisMetricKey::PercentOfTotal !== $key,
            ));
        }

        return array_values(array_unique($normalized, \SORT_REGULAR));
    }

    public function isChartable(AnalysisMetricKey $metricKey): bool
    {
        return $this->metricCatalog->get($metricKey)->isChartable();
    }

    private function isMetricAllowedForConfig(AnalysisMetricKey $metricKey, AnalysisViewConfig $config): bool
    {
        if (AnalysisMetricKey::PercentOfTotal === $metricKey) {
            if ($config->dimensionKey->isTemporalPrimary()) {
                return false;
            }

            if (!$config->timeGrain instanceof AnalysisDimensionGrain || AnalysisDimensionGrain::Total !== $config->timeGrain) {
                return false;
            }

            return true;
        }

        if (AnalysisMetricKey::AllocationCount === $metricKey) {
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
    private function buildCompatibilityQuery(AnalysisViewConfig $config, array $metricKeys): GenericAnalysisQuery
    {
        $query = new AnalysisQuery(
            dataSourceKey: $config->dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $metricKeys[0],
            dimensionKey: $config->dimensionKey,
            timeGrain: $config->timeGrain,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );

        return $this->queryMapper->map($query);
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function hasRateMetric(array $metricKeys): bool
    {
        return array_any($metricKeys, fn ($metricKey): bool => ExplorerMetricCategory::Rate === $metricKey->metricCategory());
    }
}
