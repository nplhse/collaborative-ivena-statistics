<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class NormalizedAnalysisResult
{
    /**
     * @param list<EnrichedAnalysisRow>              $rows
     * @param array<string, mixed>                   $chartData
     * @param list<string>                           $metricKeys
     * @param list<GenericAnalysisTableMetricColumn> $metricColumns
     */
    public function __construct(
        public string $title,
        public string $primaryDimensionLabel,
        public ?string $seriesDimensionLabel,
        public int $grandTotal,
        public array $rows,
        public array $chartData,
        public array $metricKeys,
        public array $metricColumns,
        public string $visualMetricKey,
        public string $recommendedChartType = 'bar',
        public string $distributionBaseMetricKey = 'count',
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
    ) {
    }
}
