<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use Doctrine\DBAL\Connection;

final readonly class GetOverviewGenderDistributionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, int> keys M, F, X
     */
    public function __invoke(OverviewQueryCriteria $criteria): array
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return [];
        }

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);
        $sql = <<<SQL
SELECT gender_code AS code, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY gender_code
SQL;

        /** @var list<array{code: int|string|null, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $out = [];
        foreach ($rows as $row) {
            if (null === $row['code']) {
                continue;
            }
            $key = match ((int) $row['code']) {
                AllocationStatsGenderProjectionCode::Male->value => 'M',
                AllocationStatsGenderProjectionCode::Female->value => 'F',
                AllocationStatsGenderProjectionCode::Other->value => 'X',
                default => null,
            };
            if (null !== $key) {
                $out[$key] = (int) $row['count'];
            }
        }

        return $out;
    }
}
