<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\DepartmentWasClosedSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentSliceData;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use Doctrine\DBAL\Connection;

final readonly class ClosedDepartmentSliceQuery
{
    private const int TOP_LIMIT = 10;

    private const int RANKING_LIMIT = 40;

    /** @var list<string> */
    private const array SUMMARY_KINDS = ['time_series', 'weekday_day_time'];

    /** @var list<string> */
    private const array RANKING_KINDS = [
        'department',
        'speciality',
        'indication',
        'occasion',
        'assignment',
        'infection',
    ];

    /** @var list<string> */
    private const array DETAILS_KINDS = ['transport_closed', 'transport_regular'];

    private const string DISPATCH_KIND = 'dispatch_area';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ClosedDepartmentSliceData {
        return $this->fetchParts(
            $from,
            $toExclusive,
            $scope,
            $timeSeriesGrain,
            [...self::SUMMARY_KINDS, ...self::RANKING_KINDS, self::DISPATCH_KIND, ...self::DETAILS_KINDS],
        );
    }

    public function fetchSummary(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ClosedDepartmentSliceData {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ClosedDepartmentSliceData::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope, 'p');
        $closed = DepartmentWasClosedSql::closed('p');
        $timeSeriesSets = TimeSeriesGrain::Day === $timeSeriesGrain
            ? '(p.created_year, p.created_month, p.created_day)'
            : '(p.created_year, p.created_month)';
        $timeSeriesDim3 = TimeSeriesGrain::Day === $timeSeriesGrain
            ? 'p.created_day::text'
            : 'NULL::text';

        $sql = <<<SQL
SELECT
    CASE WHEN GROUPING(p.created_weekday) = 0 THEN 'weekday_day_time' ELSE 'time_series' END AS slice_kind,
    CASE WHEN GROUPING(p.created_weekday) = 0 THEN p.created_weekday::text ELSE p.created_year::text END AS dim1,
    CASE WHEN GROUPING(p.created_weekday) = 0 THEN (p.created_hour / 2)::text ELSE p.created_month::text END AS dim2,
    CASE WHEN GROUPING(p.created_weekday) = 0 THEN NULL::text ELSE {$timeSeriesDim3} END AS dim3,
    COUNT(*) FILTER (WHERE {$closed})::int AS closed_count,
    COUNT(*)::int AS total_count
FROM allocation_stats_projection p
WHERE {$where}
GROUP BY GROUPING SETS (
    {$timeSeriesSets},
    (p.created_weekday, (p.created_hour / 2))
)
SQL;

        /** @var list<array{slice_kind: string, dim1: ?string, dim2: ?string, dim3: ?string, closed_count: int|string, total_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->parseRows($rows);
    }

    public function fetchRankings(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ClosedDepartmentSliceData {
        return $this->fetchParts($from, $toExclusive, $scope, $timeSeriesGrain, self::RANKING_KINDS);
    }

    /**
     * @param list<string> $kinds
     */
    public function fetchKinds(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        array $kinds,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ClosedDepartmentSliceData {
        return $this->fetchParts($from, $toExclusive, $scope, $timeSeriesGrain, $kinds);
    }

    public function fetchDetails(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ClosedDepartmentSliceData {
        return $this->fetchParts($from, $toExclusive, $scope, $timeSeriesGrain, self::DETAILS_KINDS);
    }

    /**
     * @param list<string> $kinds
     */
    private function fetchParts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        TimeSeriesGrain $timeSeriesGrain,
        array $kinds,
    ): ClosedDepartmentSliceData {
        if ([] === $kinds || (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds)) {
            return ClosedDepartmentSliceData::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope, 'p');
        $closed = DepartmentWasClosedSql::closed('p');
        $regular = DepartmentWasClosedSql::regular('p');
        $transportBucket = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;
        $timeSeriesDim3 = TimeSeriesGrain::Day === $timeSeriesGrain ? 'p.created_day::text' : 'NULL::text';
        $timeSeriesGroupBy = TimeSeriesGrain::Day === $timeSeriesGrain
            ? 'p.created_year, p.created_month, p.created_day'
            : 'p.created_year, p.created_month';

        $fragments = [];
        foreach ($kinds as $kind) {
            $fragments[] = $this->sqlForKind(
                $kind,
                $where,
                $closed,
                $regular,
                $transportBucket,
                $timeSeriesDim3,
                $timeSeriesGroupBy,
            );
        }

        $sql = implode("\nUNION ALL\n", $fragments);

        /** @var list<array{slice_kind: string, dim1: ?string, dim2: ?string, dim3: ?string, closed_count: int|string, total_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->parseRows($rows);
    }

    private function sqlForKind(
        string $kind,
        string $where,
        string $closed,
        string $regular,
        string $transportBucket,
        string $timeSeriesDim3,
        string $timeSeriesGroupBy,
    ): string {
        return match ($kind) {
            'time_series' => <<<SQL
SELECT 'time_series' AS slice_kind, p.created_year::text AS dim1, p.created_month::text AS dim2, {$timeSeriesDim3} AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*)::int AS total_count
FROM allocation_stats_projection p
WHERE {$where}
GROUP BY {$timeSeriesGroupBy}
SQL,
            'department' => <<<SQL
SELECT 'department' AS slice_kind, p.department_id::text AS dim1, COALESCE(d.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*)::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN department d ON d.id = p.department_id
WHERE {$where}
GROUP BY p.department_id, d.name
HAVING COUNT(*) FILTER (WHERE {$closed}) > 0
SQL,
            'speciality' => <<<SQL
SELECT 'speciality' AS slice_kind, p.speciality_id::text AS dim1, COALESCE(s.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*)::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN speciality s ON s.id = p.speciality_id
WHERE {$where}
GROUP BY p.speciality_id, s.name
HAVING COUNT(*) FILTER (WHERE {$closed}) > 0
SQL,
            'indication' => <<<SQL
SELECT 'indication' AS slice_kind, p.indication_normalized_id::text AS dim1, COALESCE(i.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*) FILTER (WHERE {$closed})::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN indication_normalized i ON i.id = p.indication_normalized_id
WHERE {$where} AND {$closed}
GROUP BY p.indication_normalized_id, i.name
SQL,
            'occasion' => <<<SQL
SELECT 'occasion' AS slice_kind, p.occasion_id::text AS dim1, COALESCE(o.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*) FILTER (WHERE {$closed})::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN occasion o ON o.id = p.occasion_id
WHERE {$where} AND {$closed}
GROUP BY p.occasion_id, o.name
SQL,
            'assignment' => <<<SQL
SELECT 'assignment' AS slice_kind, p.assignment_id::text AS dim1, COALESCE(a.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*) FILTER (WHERE {$closed})::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN assignment a ON a.id = p.assignment_id
WHERE {$where} AND {$closed}
GROUP BY p.assignment_id, a.name
SQL,
            'infection' => <<<SQL
SELECT 'infection' AS slice_kind, p.infection_id::text AS dim1, COALESCE(inf.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*) FILTER (WHERE {$closed})::int AS total_count
FROM allocation_stats_projection p
INNER JOIN infection inf ON inf.id = p.infection_id
WHERE {$where} AND {$closed}
GROUP BY p.infection_id, inf.name
SQL,
            'dispatch_area' => <<<SQL
SELECT 'dispatch_area' AS slice_kind, p.dispatch_area_id::text AS dim1, COALESCE(da.name, '') AS dim2, NULL::text AS dim3,
       COUNT(*) FILTER (WHERE {$closed})::int AS closed_count, COUNT(*) FILTER (WHERE {$closed})::int AS total_count
FROM allocation_stats_projection p
LEFT JOIN dispatch_area da ON da.id = p.dispatch_area_id
WHERE {$where} AND {$closed}
GROUP BY p.dispatch_area_id, da.name
SQL,
            'transport_closed' => <<<SQL
SELECT 'transport_closed' AS slice_kind, bucket AS dim1, NULL::text AS dim2, NULL::text AS dim3, COUNT(*)::int AS closed_count, COUNT(*)::int AS total_count
FROM (
    SELECT {$transportBucket} AS bucket
    FROM allocation_stats_projection p
    WHERE {$where} AND {$closed}
) grouped
GROUP BY bucket
SQL,
            'transport_regular' => <<<SQL
SELECT 'transport_regular' AS slice_kind, bucket AS dim1, NULL::text AS dim2, NULL::text AS dim3, COUNT(*)::int AS closed_count, COUNT(*)::int AS total_count
FROM (
    SELECT {$transportBucket} AS bucket
    FROM allocation_stats_projection p
    WHERE {$where} AND {$regular}
) grouped
GROUP BY bucket
SQL,
            'weekday_day_time' => <<<SQL
SELECT 'weekday_day_time' AS slice_kind, p.created_weekday::text AS dim1, (p.created_hour / 2)::text AS dim2, NULL::text AS dim3, COUNT(*)::int AS closed_count, COUNT(*)::int AS total_count
FROM allocation_stats_projection p
WHERE {$where} AND {$closed}
GROUP BY p.created_weekday, (p.created_hour / 2)
SQL,
            default => throw new \InvalidArgumentException(sprintf('Unknown closed-department slice kind "%s".', $kind)),
        };
    }

    /**
     * @param list<array{slice_kind: string, dim1: ?string, dim2: ?string, dim3: ?string, closed_count: int|string, total_count: int|string}> $rows
     */
    private function parseRows(array $rows): ClosedDepartmentSliceData
    {
        $timeSeriesRows = [];
        $departments = [];
        $specialities = [];
        $indications = [];
        $occasions = [];
        $assignments = [];
        $infections = [];
        $dispatchAreas = [];
        $closedTransportBuckets = [];
        $regularTransportBuckets = [];
        $weekdayDayTimeCells = [];

        foreach ($rows as $row) {
            $kind = $row['slice_kind'];
            $closed = (int) $row['closed_count'];
            $total = (int) $row['total_count'];
            $dim1 = $row['dim1'];
            $dim2 = $row['dim2'] ?? '';

            match ($kind) {
                'time_series' => $timeSeriesRows[] = $this->timeSeriesRow($dim1, $dim2, $row['dim3'] ?? null, $closed, $total),
                'department' => $departments[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'speciality' => $specialities[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'indication' => $indications[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'occasion' => $occasions[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'assignment' => $assignments[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'infection' => $infections[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'dispatch_area' => $dispatchAreas[] = $this->namedRow($dim1, $dim2, $closed, $total),
                'transport_closed' => $closedTransportBuckets[(string) $dim1] = $closed,
                'transport_regular' => $regularTransportBuckets[(string) $dim1] = $closed,
                'weekday_day_time' => $closed > 0 ? $weekdayDayTimeCells[] = [
                    'weekday' => (int) $dim1,
                    'twoHourSlot' => (int) $dim2,
                    'count' => $closed,
                ] : null,
                default => null,
            };
        }

        usort($departments, $this->sortByClosedDesc(...));
        usort($specialities, $this->sortByClosedDesc(...));
        usort($indications, $this->sortByClosedDesc(...));
        usort($occasions, $this->sortByClosedDesc(...));
        usort($assignments, $this->sortByClosedDesc(...));
        usort($infections, $this->sortByClosedDesc(...));
        usort($dispatchAreas, $this->sortByClosedDesc(...));

        return new ClosedDepartmentSliceData(
            $timeSeriesRows,
            array_slice($departments, 0, self::RANKING_LIMIT),
            array_slice($specialities, 0, self::RANKING_LIMIT),
            array_slice($indications, 0, self::RANKING_LIMIT),
            array_slice($occasions, 0, self::RANKING_LIMIT),
            array_slice($assignments, 0, self::RANKING_LIMIT),
            array_slice($infections, 0, self::RANKING_LIMIT),
            array_slice($dispatchAreas, 0, self::TOP_LIMIT),
            $closedTransportBuckets,
            $regularTransportBuckets,
            $weekdayDayTimeCells,
        );
    }

    /**
     * @param array{id: ?int, name: string, closed: int, total: int} $left
     * @param array{id: ?int, name: string, closed: int, total: int} $right
     */
    private function sortByClosedDesc(array $left, array $right): int
    {
        return $right['closed'] <=> $left['closed'];
    }

    /**
     * @return array{year: int, month: int, day?: int, closed: int, total: int}
     */
    private function timeSeriesRow(?string $year, string $month, ?string $day, int $closed, int $total): array
    {
        $row = [
            'year' => (int) $year,
            'month' => (int) $month,
            'closed' => $closed,
            'total' => $total,
        ];
        if (null !== $day && '' !== $day) {
            $row['day'] = (int) $day;
        }

        return $row;
    }

    /**
     * @return array{id: ?int, name: string, closed: int, total: int}
     */
    private function namedRow(?string $id, string $name, int $closed, int $total): array
    {
        $parsedId = null !== $id && '' !== $id && ctype_digit($id) ? (int) $id : null;

        return [
            'id' => $parsedId,
            'name' => $name,
            'closed' => $closed,
            'total' => $total,
        ];
    }
}
