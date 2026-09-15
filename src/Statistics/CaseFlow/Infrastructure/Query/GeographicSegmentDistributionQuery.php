<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use Doctrine\DBAL\Connection;

final readonly class GeographicSegmentDistributionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function fetchUrgencyCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $emergency = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatient = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatient = AllocationStatsUrgencyProjectionCode::Outpatient->value;

        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            <<<SQL
    COUNT(*) FILTER (WHERE asp.urgency_code = {$emergency})::int AS urgency_{$emergency},
    COUNT(*) FILTER (WHERE asp.urgency_code = {$inpatient})::int AS urgency_{$inpatient},
    COUNT(*) FILTER (WHERE asp.urgency_code = {$outpatient})::int AS urgency_{$outpatient}
SQL,
        );

        return [
            $emergency => (int) ($row['urgency_'.$emergency] ?? 0),
            $inpatient => (int) ($row['urgency_'.$inpatient] ?? 0),
            $outpatient => (int) ($row['urgency_'.$outpatient] ?? 0),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function fetchGenderCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $male = AllocationStatsGenderProjectionCode::Male->value;
        $female = AllocationStatsGenderProjectionCode::Female->value;
        $other = AllocationStatsGenderProjectionCode::Other->value;

        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            <<<SQL
    COUNT(*) FILTER (WHERE asp.gender_code = {$male})::int AS gender_{$male},
    COUNT(*) FILTER (WHERE asp.gender_code = {$female})::int AS gender_{$female},
    COUNT(*) FILTER (WHERE asp.gender_code = {$other})::int AS gender_{$other}
SQL,
        );

        return [
            $male => (int) ($row['gender_'.$male] ?? 0),
            $female => (int) ($row['gender_'.$female] ?? 0),
            $other => (int) ($row['gender_'.$other] ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function fetchAgeCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        [$where, $params, $types] = $this->buildWhere(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );
        if (null === $where) {
            return [];
        }

        $bucketSql = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        $sql = <<<SQL
SELECT bucket_key, COUNT(*)::int AS case_count
FROM (
    SELECT {$bucketSql} AS bucket_key
    FROM allocation_stats_projection asp
    WHERE {$where}
) sub
GROUP BY bucket_key
SQL;

        $counts = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $counts[(string) $row['bucket_key']] = (int) $row['case_count'];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function fetchResourceCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            <<<'SQL'
    COUNT(*) FILTER (WHERE asp.requires_resus IS TRUE)::int AS resus,
    COUNT(*) FILTER (WHERE asp.requires_cathlab IS TRUE)::int AS cathlab,
    COUNT(*) FILTER (WHERE asp.is_with_physician IS TRUE)::int AS with_physician,
    COUNT(*) FILTER (WHERE asp.is_cpr IS TRUE)::int AS cpr,
    COUNT(*) FILTER (WHERE asp.is_ventilated IS TRUE)::int AS ventilation,
    COUNT(*) FILTER (WHERE asp.is_shock IS TRUE)::int AS shock
SQL,
        );

        return [
            'resus' => (int) ($row['resus'] ?? 0),
            'cathlab' => (int) ($row['cathlab'] ?? 0),
            'with_physician' => (int) ($row['with_physician'] ?? 0),
            'cpr' => (int) ($row['cpr'] ?? 0),
            'ventilation' => (int) ($row['ventilation'] ?? 0),
            'shock' => (int) ($row['shock'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFilterRow(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId,
        ?StatisticsDrawerFilter $drawerFilter,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
        string $selectList,
    ): array {
        [$where, $params, $types] = $this->buildWhere(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );
        if (null === $where) {
            return [];
        }

        $sql = <<<SQL
SELECT
    {$selectList}
FROM allocation_stats_projection asp
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);

        return false === $row ? [] : $row;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, \Doctrine\DBAL\ArrayParameterType>}|array{0: null, 1: array<string, mixed>, 2: array<string, \Doctrine\DBAL\ArrayParameterType>}
     */
    private function buildWhere(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId,
        ?StatisticsDrawerFilter $drawerFilter,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
    ): array {
        if (CaseFlowSqlFilter::isImpossibleScope($scope, $originStateId)) {
            return [null, [], []];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            $from,
            $toExclusive,
            $scope,
            'asp',
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );

        return GeographicSegmentSql::append($where, $params, $types, $segment);
    }
}
