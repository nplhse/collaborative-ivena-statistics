<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Comparison;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;

/**
 * Hospitals that belong to a scope, for the reference comparison (#1346).
 */
interface ScopeHospitalIdsReaderInterface
{
    /**
     * @return list<int> distinct hospital ids
     */
    public function hospitalIds(StatisticsScopeCriteria $scope): array;
}
