<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\DepartmentWasClosedSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentMetricsRow;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use Doctrine\DBAL\Connection;

final readonly class ClosedDepartmentMetricsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function fetchKpis(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): ClosedDepartmentMetricsRow {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ClosedDepartmentMetricsRow::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $closed = DepartmentWasClosedSql::closed();
        $mean = StatisticsTransportTimeSql::meanPreciseMinutes();

        $sql = <<<SQL
SELECT
    COUNT(*)::int AS total_count,
    COUNT(*) FILTER (WHERE {$closed})::int AS closed_count,
    COUNT(DISTINCT department_id) FILTER (WHERE {$closed})::int AS closed_department_count,
    COUNT(DISTINCT department_id)::int AS total_department_count,
    {$mean} FILTER (WHERE {$closed}) AS closed_mean_transport
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return ClosedDepartmentMetricsRow::empty();
        }

        return ClosedDepartmentMetricsRow::fromKpis(
            (int) ($row['total_count'] ?? 0),
            (int) ($row['closed_count'] ?? 0),
            (int) ($row['closed_department_count'] ?? 0),
            (int) ($row['total_department_count'] ?? 0),
            $this->toFloatOrNull($row['closed_mean_transport'] ?? null),
        );
    }

    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): ClosedDepartmentMetricsRow {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ClosedDepartmentMetricsRow::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);

        $closed = DepartmentWasClosedSql::closed();
        $regular = DepartmentWasClosedSql::regular();
        $sk1 = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $sk2 = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $sk3 = AllocationStatsUrgencyProjectionCode::Outpatient->value;
        $male = AllocationStatsGenderProjectionCode::Male->value;
        $female = AllocationStatsGenderProjectionCode::Female->value;
        $other = AllocationStatsGenderProjectionCode::Other->value;
        $mean = StatisticsTransportTimeSql::meanPreciseMinutes();

        $sql = <<<SQL
SELECT
    COUNT(*)::int AS total_count,
    COUNT(*) FILTER (WHERE {$closed})::int AS closed_count,
    COUNT(*) FILTER (WHERE {$regular})::int AS regular_count,
    COUNT(DISTINCT department_id) FILTER (WHERE {$closed})::int AS closed_department_count,
    COUNT(DISTINCT department_id)::int AS total_department_count,
    COUNT(*) FILTER (WHERE {$closed} AND urgency_code = {$sk1})::int AS closed_sk1,
    COUNT(*) FILTER (WHERE {$regular} AND urgency_code = {$sk1})::int AS regular_sk1,
    COUNT(*) FILTER (WHERE {$closed} AND urgency_code = {$sk2})::int AS closed_sk2,
    COUNT(*) FILTER (WHERE {$regular} AND urgency_code = {$sk2})::int AS regular_sk2,
    COUNT(*) FILTER (WHERE {$closed} AND urgency_code = {$sk3})::int AS closed_sk3,
    COUNT(*) FILTER (WHERE {$regular} AND urgency_code = {$sk3})::int AS regular_sk3,
    COUNT(*) FILTER (WHERE {$closed} AND gender_code = {$male})::int AS closed_male,
    COUNT(*) FILTER (WHERE {$regular} AND gender_code = {$male})::int AS regular_male,
    COUNT(*) FILTER (WHERE {$closed} AND gender_code = {$female})::int AS closed_female,
    COUNT(*) FILTER (WHERE {$regular} AND gender_code = {$female})::int AS regular_female,
    COUNT(*) FILTER (WHERE {$closed} AND gender_code = {$other})::int AS closed_other,
    COUNT(*) FILTER (WHERE {$regular} AND gender_code = {$other})::int AS regular_other,
    COUNT(*) FILTER (WHERE {$closed} AND is_with_physician = true)::int AS closed_with_physician,
    COUNT(*) FILTER (WHERE {$regular} AND is_with_physician = true)::int AS regular_with_physician,
    COUNT(*) FILTER (WHERE {$closed} AND requires_resus = true)::int AS closed_resus,
    COUNT(*) FILTER (WHERE {$regular} AND requires_resus = true)::int AS regular_resus,
    COUNT(*) FILTER (WHERE {$closed} AND requires_cathlab = true)::int AS closed_cathlab,
    COUNT(*) FILTER (WHERE {$regular} AND requires_cathlab = true)::int AS regular_cathlab,
    COUNT(*) FILTER (WHERE {$closed} AND is_cpr = true)::int AS closed_cpr,
    COUNT(*) FILTER (WHERE {$regular} AND is_cpr = true)::int AS regular_cpr,
    COUNT(*) FILTER (WHERE {$closed} AND is_ventilated = true)::int AS closed_ventilated,
    COUNT(*) FILTER (WHERE {$regular} AND is_ventilated = true)::int AS regular_ventilated,
    COUNT(*) FILTER (WHERE {$closed} AND is_shock = true)::int AS closed_shock,
    COUNT(*) FILTER (WHERE {$regular} AND is_shock = true)::int AS regular_shock,
    COUNT(*) FILTER (WHERE {$closed} AND is_pregnant = true)::int AS closed_pregnant,
    COUNT(*) FILTER (WHERE {$regular} AND is_pregnant = true)::int AS regular_pregnant,
    {$mean} FILTER (WHERE {$closed}) AS closed_mean_transport,
    {$mean} FILTER (WHERE {$regular}) AS regular_mean_transport
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return ClosedDepartmentMetricsRow::empty();
        }

        return new ClosedDepartmentMetricsRow(
            (int) ($row['total_count'] ?? 0),
            (int) ($row['closed_count'] ?? 0),
            (int) ($row['regular_count'] ?? 0),
            (int) ($row['closed_department_count'] ?? 0),
            (int) ($row['total_department_count'] ?? 0),
            (int) ($row['closed_sk1'] ?? 0),
            (int) ($row['regular_sk1'] ?? 0),
            (int) ($row['closed_sk2'] ?? 0),
            (int) ($row['regular_sk2'] ?? 0),
            (int) ($row['closed_sk3'] ?? 0),
            (int) ($row['regular_sk3'] ?? 0),
            (int) ($row['closed_male'] ?? 0),
            (int) ($row['regular_male'] ?? 0),
            (int) ($row['closed_female'] ?? 0),
            (int) ($row['regular_female'] ?? 0),
            (int) ($row['closed_other'] ?? 0),
            (int) ($row['regular_other'] ?? 0),
            (int) ($row['closed_with_physician'] ?? 0),
            (int) ($row['regular_with_physician'] ?? 0),
            (int) ($row['closed_resus'] ?? 0),
            (int) ($row['regular_resus'] ?? 0),
            (int) ($row['closed_cathlab'] ?? 0),
            (int) ($row['regular_cathlab'] ?? 0),
            (int) ($row['closed_cpr'] ?? 0),
            (int) ($row['regular_cpr'] ?? 0),
            (int) ($row['closed_ventilated'] ?? 0),
            (int) ($row['regular_ventilated'] ?? 0),
            (int) ($row['closed_shock'] ?? 0),
            (int) ($row['regular_shock'] ?? 0),
            (int) ($row['closed_pregnant'] ?? 0),
            (int) ($row['regular_pregnant'] ?? 0),
            $this->toFloatOrNull($row['closed_mean_transport'] ?? null),
            $this->toFloatOrNull($row['regular_mean_transport'] ?? null),
        );
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
