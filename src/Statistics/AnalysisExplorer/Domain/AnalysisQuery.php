<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;

final readonly class AnalysisQuery
{
    /**
     * @param list<AnalysisMetricKey> $metricKeys
     * @param list<AnalysisFilter>    $filters
     * @param list<int>               $referenceHospitalIds effective reference hospitals of the comparison (#1346)
     */
    public function __construct(
        public AnalysisDataSourceKey $dataSourceKey,
        public array $metricKeys,
        public AnalysisMetricKey $visualMetricKey,
        public AnalysisAxisRef $rowAxis,
        public ?AnalysisAxisRef $columnAxis,
        public StatisticsScopeCriteria $scopeCriteria,
        public StatisticsPeriodBounds $periodBounds,
        public ExplorerHospitalPopulationMode $hospitalPopulationMode = ExplorerHospitalPopulationMode::Participating,
        public array $filters = [],
        public array $referenceHospitalIds = [],
    ) {
    }

    public function hasColumnAxis(): bool
    {
        return $this->columnAxis instanceof AnalysisAxisRef;
    }
}
