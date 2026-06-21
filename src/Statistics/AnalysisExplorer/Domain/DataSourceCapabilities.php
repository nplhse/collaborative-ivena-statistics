<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class DataSourceCapabilities
{
    /**
     * @param list<AnalysisMetricKey>      $supportedMetrics
     * @param list<AnalysisDimensionGrain> $supportedDimensions
     */
    public function __construct(
        public AnalysisDataSourceKey $key,
        public array $supportedMetrics,
        public array $supportedDimensions,
        public AnalysisMetricKey $defaultMetric,
        public AnalysisDimensionGrain $defaultDimension,
    ) {
    }
}
