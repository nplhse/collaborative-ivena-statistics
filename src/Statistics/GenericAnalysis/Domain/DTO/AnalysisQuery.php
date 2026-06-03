<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class AnalysisQuery
{
    /**
     * @param list<AnalysisFilter> $filters
     */
    public function __construct(
        public string $primaryDimensionKey,
        public StatisticsScopeCriteria $scopeCriteria,
        public StatisticsPeriodBounds $periodBounds,
        public ?string $seriesDimensionKey = null,
        public AnalysisMetric $metric = new AnalysisMetric(),
        public array $filters = [],
        public bool $includeNullBuckets = false,
    ) {
    }
}
