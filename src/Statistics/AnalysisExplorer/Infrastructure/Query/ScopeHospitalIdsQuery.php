<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Application\Comparison\ScopeHospitalIdsReaderInterface;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use Doctrine\DBAL\Connection;

/**
 * Hospitals of a scope for the reference comparison (#1346): the distinct hospitals with allocations in the projection
 * under the same scope conditions as the analysis, independent of the period.
 *
 * The scope kinds count hospitals differently elsewhere (state and cohort lists, a dispatch area count from the
 * projection, no check for "all" and "my hospitals"). The comparison needs ids, to check that each reference belongs
 * to the scope, and one rule for every scope kind. Counting only hospitals with data is the conservative choice:
 * a hospital without allocations cannot shield the figures of another one.
 *
 * @psalm-suppress UnusedClass Wired as ScopeHospitalIdsReaderInterface.
 */
final readonly class ScopeHospitalIdsQuery implements ScopeHospitalIdsReaderInterface
{
    public function __construct(
        private Connection $connection,
        private GenericAnalysisScopeSqlFilter $scopeSqlFilter,
    ) {
    }

    #[\Override]
    public function hospitalIds(StatisticsScopeCriteria $scope): array
    {
        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod($scope, new StatisticsPeriodBounds(null));

        $ids = $this->connection->fetchFirstColumn(
            sprintf(
                'SELECT DISTINCT hospital_id FROM %s WHERE %s AND hospital_id IS NOT NULL ORDER BY hospital_id',
                $this->scopeSqlFilter->tableName(),
                implode(' AND ', $conditions),
            ),
            $params,
            $this->scopeSqlFilter->parameterTypes($params),
        );

        return array_map(intval(...), $ids);
    }
}
