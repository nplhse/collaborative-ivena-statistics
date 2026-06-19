<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;

final readonly class AnalysisQuery
{
    /**
     * @param list<AnalysisFilter> $filters
     * @param list<string>         $metricKeys
     * @param list<string>         $chartMetricKeys
     */
    public function __construct(
        public string $primaryDimensionKey,
        public StatisticsScopeCriteria $scopeCriteria,
        public StatisticsPeriodBounds $periodBounds,
        public ?string $seriesDimensionKey = null,
        public array $metricKeys = [],
        public ?string $visualMetricKey = null,
        public array $filters = [],
        public bool $includeNullBuckets = false,
        public AnalysisSeriesMode $seriesMode = AnalysisSeriesMode::ByDimension,
        public ?GenericAnalysisChartType $chartType = null,
        public AnalysisDisplayMode $displayMode = AnalysisDisplayMode::Chart,
        public array $chartMetricKeys = [],
        public int $configVersion = 2,
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
        public HospitalPopulationMode $hospitalPopulationMode = HospitalPopulationMode::All,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolvedMetricKeys(): array
    {
        if ([] === $this->metricKeys) {
            return [$this->dataSource->defaultMetricKey()];
        }

        return $this->metricKeys;
    }

    public function resolvedVisualMetricKey(): string
    {
        $keys = $this->resolvedMetricKeys();
        $defaultKey = $this->dataSource->defaultMetricKey();

        if (null !== $this->visualMetricKey && \in_array($this->visualMetricKey, $keys, true)) {
            return $this->visualMetricKey;
        }

        if (\in_array($defaultKey, $keys, true)) {
            return $defaultKey;
        }

        return $keys[0];
    }

    /**
     * @return list<string>
     */
    public function resolvedChartMetricKeys(): array
    {
        if (AnalysisSeriesMode::ByMetric !== $this->seriesMode) {
            return [$this->resolvedVisualMetricKey()];
        }

        if ([] !== $this->chartMetricKeys) {
            return array_values(array_filter(
                $this->chartMetricKeys,
                fn (string $key): bool => \in_array($key, $this->resolvedMetricKeys(), true),
            ));
        }

        $keys = array_values(array_filter(
            $this->resolvedMetricKeys(),
            static fn (string $key): bool => $this->dataSource->defaultMetricKey() !== $key,
        ));

        if ([] === $keys) {
            return [$this->dataSource->defaultMetricKey()];
        }

        return $keys;
    }

    public function effectiveSeriesDimensionKey(): ?string
    {
        if (AnalysisSeriesMode::ByMetric === $this->seriesMode) {
            return null;
        }

        return $this->seriesDimensionKey;
    }

    public function withEffectiveSeriesForQuery(): self
    {
        if (null === $this->effectiveSeriesDimensionKey()) {
            return new self(
                primaryDimensionKey: $this->primaryDimensionKey,
                scopeCriteria: $this->scopeCriteria,
                periodBounds: $this->periodBounds,
                seriesDimensionKey: null,
                metricKeys: $this->metricKeys,
                visualMetricKey: $this->visualMetricKey,
                filters: $this->filters,
                includeNullBuckets: $this->includeNullBuckets,
                seriesMode: $this->seriesMode,
                chartType: $this->chartType,
                displayMode: $this->displayMode,
                chartMetricKeys: $this->chartMetricKeys,
                configVersion: $this->configVersion,
                dataSource: $this->dataSource,
                hospitalPopulationMode: $this->hospitalPopulationMode,
            );
        }

        return $this;
    }
}
