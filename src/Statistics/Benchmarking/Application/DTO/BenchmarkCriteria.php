<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class BenchmarkCriteria
{
    public function __construct(
        public StatisticsScopeCriteria $primaryScope,
        public StatisticsScopeCriteria $comparisonScope,
        public StatisticsPeriodBounds $primaryPeriod,
        public StatisticsPeriodBounds $comparisonPeriod,
        public string $primaryScopeLabel,
        public string $comparisonScopeLabel,
        public string $primaryPeriodLabel,
        public string $comparisonPeriodLabel,
    ) {
    }
}
