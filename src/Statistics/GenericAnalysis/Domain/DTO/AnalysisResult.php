<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class AnalysisResult
{
    /**
     * @param list<AnalysisResultRow> $rows
     * @param list<string>            $metricKeys
     */
    public function __construct(
        public array $rows,
        public int $grandTotal,
        public string $primaryDimensionKey,
        public array $metricKeys,
        public ?string $seriesDimensionKey = null,
        public bool $includeNullBuckets = false,
        public string $distributionBaseMetricKey = 'count',
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
    ) {
    }

    public function distributionBaseMetricKey(): string
    {
        return $this->distributionBaseMetricKey;
    }
}
