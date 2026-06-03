<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

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
    ) {
    }
}
