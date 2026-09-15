<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\GeographicSegmentMetricsRow;
use Doctrine\DBAL\Connection;

final readonly class GeographicSegmentMetricsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): GeographicSegmentMetricsRow {
        if (CaseFlowSqlFilter::isImpossibleScope($scope, $originStateId)) {
            return new GeographicSegmentMetricsRow(0, 0, null);
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
        $medianTransport = StatisticsTransportTimeSql::medianPreciseMinutes('asp');
        $segmentCasesSql = 'COUNT(*)::int';
        $medianSql = $medianTransport;
        if ($segment instanceof GeographicSegment) {
            [$segmentPredicate, $segmentParams] = $segment->sqlPredicate('asp');
            $params = [...$params, ...$segmentParams];
            $segmentCasesSql = "COUNT(*) FILTER (WHERE {$segmentPredicate})::int";
            $medianSql = "{$medianTransport} FILTER (WHERE {$segmentPredicate})";
        }

        $sql = <<<SQL
SELECT
    COUNT(*)::int AS population_cases,
    {$segmentCasesSql} AS segment_cases,
    {$medianSql} AS median_transport_minutes
FROM allocation_stats_projection asp
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return new GeographicSegmentMetricsRow(0, 0, null);
        }

        return new GeographicSegmentMetricsRow(
            (int) $row['population_cases'],
            (int) $row['segment_cases'],
            null !== $row['median_transport_minutes'] ? (float) $row['median_transport_minutes'] : null,
        );
    }
}
