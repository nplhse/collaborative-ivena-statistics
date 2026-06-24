<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;

final readonly class AnalysisQuery
{
    /**
     * @param list<AnalysisFilter> $filters
     * @param list<string>         $metricKeys
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
}
