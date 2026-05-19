<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewScopedTotalsResult;
use Doctrine\DBAL\Connection;

final readonly class GetOverviewScopedTotalsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param non-empty-list<int> $scopedHospitalIds
     */
    public function __invoke(OverviewQueryCriteria $periodCriteria, array $scopedHospitalIds): OverviewScopedTotalsResult
    {
        $platformCriteria = new OverviewQueryCriteria(
            $periodCriteria->from,
            $periodCriteria->toExclusive,
            null,
        );
        $scopedCriteria = new OverviewQueryCriteria(
            $periodCriteria->from,
            $periodCriteria->toExclusive,
            $scopedHospitalIds,
        );

        return new OverviewScopedTotalsResult(
            $this->countRows($platformCriteria),
            $this->countRows($scopedCriteria),
        );
    }

    private function countRows(OverviewQueryCriteria $criteria): int
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return 0;
        }

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);
        $sql = sprintf('SELECT COUNT(*)::int AS cnt FROM allocation_stats_projection WHERE %s', $where);

        return (int) $this->connection->fetchOne($sql, $params);
    }
}
