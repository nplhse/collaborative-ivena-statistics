<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowBucketRow;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowTransportDistributionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CaseFlowBucketRow>
     */
    public function fetchTransportTime(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        return $this->fetchBucketed($from, $toExclusive, $scope, StatisticsTransportTimeBucketSql::CASE_EXPRESSION);
    }

    /**
     * @return list<CaseFlowBucketRow>
     */
    public function fetchUrgency(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        return $this->fetchBucketed(
            $from,
            $toExclusive,
            $scope,
            "CASE asp.urgency_code WHEN 1 THEN 'emergency' WHEN 2 THEN 'inpatient' WHEN 3 THEN 'outpatient' ELSE 'unknown' END",
        );
    }

    /**
     * @return list<CaseFlowBucketRow>
     */
    private function fetchBucketed(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        string $bucketExpression,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);

        $sql = <<<SQL
SELECT
    bucket_key,
    COUNT(*) AS case_count
FROM (
    SELECT {$bucketExpression} AS bucket_key
    FROM allocation_stats_projection asp
    WHERE {$where}
) sub
GROUP BY bucket_key
ORDER BY case_count DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): CaseFlowBucketRow => new CaseFlowBucketRow(
                (string) $row['bucket_key'],
                (int) $row['case_count'],
            ),
            $rows,
        );
    }
}
