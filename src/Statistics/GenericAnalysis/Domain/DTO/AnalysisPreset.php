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
        public ?GenericAnalysisChartType $chartType = null,
        public AnalysisSeriesMode $seriesMode = AnalysisSeriesMode::ByDimension,
        public AnalysisDisplayMode $displayMode = AnalysisDisplayMode::Chart,
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
        public HospitalPopulationMode $hospitalPopulationMode = HospitalPopulationMode::All,
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
            seriesMode: $this->seriesMode,
            chartType: $this->chartType,
            displayMode: $this->displayMode,
            dataSource: $this->dataSource,
            hospitalPopulationMode: $this->hospitalPopulationMode,
        );
    }
}
