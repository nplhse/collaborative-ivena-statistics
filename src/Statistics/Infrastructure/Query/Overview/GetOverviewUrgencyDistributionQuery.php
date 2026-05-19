<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use Doctrine\DBAL\Connection;

final readonly class GetOverviewUrgencyDistributionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<int, int> urgency code => count
     */
    public function __invoke(OverviewQueryCriteria $criteria): array
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return [];
        }

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);
        $sql = <<<SQL
SELECT urgency_code AS code, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY urgency_code
SQL;

        /** @var list<array{code: int|string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $out = [];
        foreach ($rows as $row) {
            $urgency = (int) $row['code'];
            if (null !== AllocationStatsUrgencyProjectionCode::tryFrom($urgency)) {
                $out[$urgency] = (int) $row['count'];
            }
        }

        return $out;
    }
}
