<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use Doctrine\DBAL\Connection;

final readonly class IndicationDashboardDemographicsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, int> bucket key => count
     */
    public function ageGroupCounts(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $bucketCase = IndicationDashboardAgeBucketSql::CASE_EXPRESSION;

        $sql = <<<SQL
SELECT {$bucketCase} AS bucket, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY bucket
SQL;

        /** @var list<array{bucket:string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * @return array{male:int,female:int,other:int,unknown:int}
     */
    public function genderCounts(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
        }

        $male = AllocationStatsGenderProjectionCode::Male->value;
        $female = AllocationStatsGenderProjectionCode::Female->value;
        $other = AllocationStatsGenderProjectionCode::Other->value;

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $sql = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE gender_code = {$male})::int AS male,
    COUNT(*) FILTER (WHERE gender_code = {$female})::int AS female,
    COUNT(*) FILTER (WHERE gender_code = {$other})::int AS other,
    COUNT(*) FILTER (WHERE gender_code IS NULL)::int AS unknown
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $fetched = $this->connection->fetchAssociative($sql, $params);
        if (false === $fetched) {
            return ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
        }

        /** @var array<string, int|string> $row */
        $row = $fetched;

        return [
            'male' => (int) ($row['male'] ?? 0),
            'female' => (int) ($row['female'] ?? 0),
            'other' => (int) ($row['other'] ?? 0),
            'unknown' => (int) ($row['unknown'] ?? 0),
        ];
    }

    /**
     * @return array<string, int> bucket key => count
     */
    public function transportTimeBucketCounts(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $bucketCase = IndicationDashboardTransportTimeBucketSql::CASE_EXPRESSION;

        $sql = <<<SQL
SELECT {$bucketCase} AS bucket, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY bucket
SQL;

        /** @var list<array{bucket:string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * @return list<array{age:int,count:int}>
     */
    public function ageHistogram(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        int $maxAge = 100,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $params['max_age'] = $maxAge;
        $where .= ' AND indication_normalized_id = :indication_id AND age IS NOT NULL AND age <= :max_age';

        $sql = <<<SQL
SELECT age, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY age
ORDER BY age ASC
SQL;

        /** @var list<array{age:int|string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $row): array => ['age' => (int) $row['age'], 'count' => (int) $row['count']],
            $rows,
        );
    }
}
