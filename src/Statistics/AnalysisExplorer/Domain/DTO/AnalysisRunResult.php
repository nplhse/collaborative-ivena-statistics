<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisRunResult
{
    /**
     * @param list<AnalysisDataPoint> $dataPoints
     */
    public function __construct(
        public string $title,
        public AnalysisMetricKey $metricKey,
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $timeGrain,
        public array $dataPoints,
        public int $total,
    ) {
    }
}
