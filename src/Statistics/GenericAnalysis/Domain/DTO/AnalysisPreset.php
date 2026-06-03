<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class AnalysisPreset
{
    /**
     * @param list<string> $metricKeys
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $primaryDimensionKey,
        public ?string $seriesDimensionKey = null,
        public bool $includeNullBuckets = false,
        public array $metricKeys = [],
        public ?string $visualMetricKey = null,
    ) {
    }

    public function toQuery(
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
    ): AnalysisQuery {
        return new AnalysisQuery(
            primaryDimensionKey: $this->primaryDimensionKey,
            scopeCriteria: $scopeCriteria,
            periodBounds: $periodBounds,
            seriesDimensionKey: $this->seriesDimensionKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            includeNullBuckets: $this->includeNullBuckets,
        );
    }
}
