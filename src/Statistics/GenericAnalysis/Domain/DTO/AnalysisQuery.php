<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

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
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolvedMetricKeys(): array
    {
        if ([] === $this->metricKeys) {
            return ['count'];
        }

        return $this->metricKeys;
    }

    public function resolvedVisualMetricKey(): string
    {
        $keys = $this->resolvedMetricKeys();

        if (null !== $this->visualMetricKey && \in_array($this->visualMetricKey, $keys, true)) {
            return $this->visualMetricKey;
        }

        if (\in_array('count', $keys, true)) {
            return 'count';
        }

        return $keys[0];
    }
}
