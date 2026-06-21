<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class AnalysisQuery
{
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public AnalysisMetricKey $metricKey,
        public AnalysisDimensionGrain $dimensionGrain,
        public StatisticsScopeCriteria $scopeCriteria,
        public StatisticsPeriodBounds $periodBounds,
    ) {
    }
}
