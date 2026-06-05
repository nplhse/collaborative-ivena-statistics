<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;

final readonly class AnalysisViewDefinition
{
    /**
     * @param list<string>                   $tags
     * @param list<string>                   $metricKeys
     * @param list<GenericAnalysisChartType> $allowedChartTypes
     * @param list<AnalysisFilter>           $defaultFilters
     * @param list<string>                   $recommendedScopes
     * @param list<string>                   $recommendedPeriods
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public AnalysisViewCategory $category,
        public array $tags,
        public string $primaryDimensionKey,
        public ?string $secondaryDimensionKey = null,
        public array $metricKeys = [],
        public ?string $visualMetricKey = null,
        public GenericAnalysisChartType $chartType = GenericAnalysisChartType::Bar,
        public array $allowedChartTypes = [],
        public array $defaultFilters = [],
        public bool $includeNullBuckets = false,
        public ?string $legacyPresetKey = null,
        public bool $isFeatured = false,
        public array $recommendedScopes = [],
        public array $recommendedPeriods = [],
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
            seriesDimensionKey: $this->secondaryDimensionKey,
            metricKeys: $this->metricKeys,
            visualMetricKey: $this->visualMetricKey,
            filters: $this->defaultFilters,
            includeNullBuckets: $this->includeNullBuckets,
        );
    }

    public function presetKey(): string
    {
        return $this->legacyPresetKey ?? $this->key;
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
}
