<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class AnalysisQuery
{
    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public array $metricKeys,
        public AnalysisMetricKey $visualMetricKey,
        public AnalysisDimensionKey $dimensionKey,
        public ?AnalysisDimensionGrain $timeGrain,
        public StatisticsScopeCriteria $scopeCriteria,
        public StatisticsPeriodBounds $periodBounds,
    ) {
    }
}
