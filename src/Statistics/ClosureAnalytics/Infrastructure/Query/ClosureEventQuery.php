<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureEventQuery
{
    private const int PAGE_SIZE = 25;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{rows: list<ClosureEventRow>, total: int, page: int}
     */
    public function fetchEvents(ClosureAnalyticsCriteria $criteria, int $page): array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $page = max(1, $page);
        $total = (int) $this->connection->fetchOne(<<<SQL
WITH {$base}
SELECT COUNT(DISTINCT event_key) FROM valid_closures
SQL, $params, $types);
        $page = min($page, max(1, (int) ceil($total / self::PAGE_SIZE)));

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
listed_event_points_raw AS (
    SELECT event_key, hospital_id, clipped_start AS point, 1 AS delta FROM valid_closures
    UNION ALL
    SELECT event_key, hospital_id, clipped_end AS point, -1 AS delta FROM valid_closures
),
listed_event_points AS (
    SELECT event_key, hospital_id, point, SUM(delta) AS delta
    FROM listed_event_points_raw GROUP BY event_key, hospital_id, point
),
listed_event_sweep AS (
    SELECT event_key, hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY event_key, hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (PARTITION BY event_key, hospital_id ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM listed_event_points
),
listed_event_actual AS (
    SELECT event_key,
           SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0) AS actual_minutes
    FROM listed_event_sweep WHERE active_count > 0 AND segment_start < segment_end
    GROUP BY event_key
),
hospital_observed AS (
    SELECT hospital_id, SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0) AS observed_minutes
    FROM observed_segments GROUP BY hospital_id
),
event_totals AS (
    SELECT event_key, hospital_id, NULLIF(BTRIM(MIN(source_group_id)), '') AS source_group_id,
           MIN(clipped_start) AS starts_at, MAX(clipped_end) AS ends_at,
           COUNT(*)::int AS closure_count,
           SUM(EXTRACT(EPOCH FROM (clipped_end - clipped_start)) / 60.0) AS summed_minutes
    FROM valid_closures
    GROUP BY event_key, hospital_id
)
SELECT e.event_key, e.hospital_id, h.name AS hospital_name, e.source_group_id,
       e.starts_at, e.ends_at, e.closure_count,
       ROUND(e.summed_minutes)::int AS summed_minutes,
       ROUND(a.actual_minutes)::int AS actual_minutes,
       ROUND(o.observed_minutes)::int AS observed_minutes
FROM event_totals e
JOIN listed_event_actual a USING (event_key)
JOIN hospital_observed o ON o.hospital_id = e.hospital_id
JOIN hospital h ON h.id = e.hospital_id
ORDER BY e.starts_at DESC, e.event_key
LIMIT :limit OFFSET :offset
SQL, [...$params, 'limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE], [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        return [
            'rows' => array_map($this->eventRow(...), $rows),
            'total' => $total,
            'page' => $page,
        ];
    }

    public function fetchEvent(ClosureAnalyticsCriteria $criteria, string $eventKey): ?ClosureEventRow
    {
        $result = $this->fetchEventData($criteria, $eventKey);

        return null === $result ? null : $this->eventRow($result['event']);
    }

    /**
     * @return list<ClosureIntervalRow>
     */
    public function fetchChildren(ClosureAnalyticsCriteria $criteria, string $eventKey): array
    {
        $result = $this->fetchEventData($criteria, $eventKey);

        return null === $result ? [] : array_map($this->intervalRow(...), $result['children']);
    }

    /**
     * @return array{event: array<string, int|string|null>, children: list<array<string, int|string|null>>}|null
     */
    private function fetchEventData(ClosureAnalyticsCriteria $criteria, string $eventKey): ?array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $params['event_key'] = $eventKey;
        $types['event_key'] = ParameterType::STRING;

        /** @var list<array<string, int|string|null>> $children */
        $children = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT v.id, h.name AS hospital_name, s.name AS speciality_name, d.name AS department_name,
       v.clipped_start AS starts_at, v.clipped_end AS ends_at, v.care_level, v.reason,
       v.closure_unit, NULLIF(BTRIM(v.source_group_id), '') AS source_group_id,
       ROUND(EXTRACT(EPOCH FROM (v.clipped_end - v.clipped_start)) / 60.0)::int AS duration_minutes
FROM valid_closures v
JOIN hospital h ON h.id = v.hospital_id
JOIN speciality s ON s.id = v.speciality_id
JOIN department d ON d.id = v.department_id
WHERE v.event_key = :event_key
ORDER BY v.clipped_start, v.id
SQL, $params, $types);
        if ([] === $children) {
            return null;
        }

        /** @var array<string, int|string|null>|false $event */
        $event = $this->connection->fetchAssociative(<<<SQL
WITH {$base},
selected AS (SELECT * FROM valid_closures WHERE event_key = :event_key),
points_raw AS (
    SELECT clipped_start AS point, 1 AS delta FROM selected
    UNION ALL SELECT clipped_end, -1 FROM selected
),
points AS (SELECT point, SUM(delta) AS delta FROM points_raw GROUP BY point),
sweep AS (
    SELECT point AS segment_start, LEAD(point) OVER (ORDER BY point) AS segment_end,
           SUM(delta) OVER (ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM points
),
selected_stats AS (
    SELECT MIN(s.hospital_id)::int AS hospital_id, MIN(h.name) AS hospital_name,
           NULLIF(BTRIM(MIN(s.source_group_id)), '') AS source_group_id,
           MIN(s.clipped_start) AS starts_at, MAX(s.clipped_end) AS ends_at,
           COUNT(*)::int AS closure_count,
           ROUND(SUM(EXTRACT(EPOCH FROM (s.clipped_end - s.clipped_start)) / 60.0))::int AS summed_minutes
    FROM selected s JOIN hospital h ON h.id = s.hospital_id
)
SELECT :event_key AS event_key, ss.hospital_id, ss.hospital_name, ss.source_group_id,
       ss.starts_at, ss.ends_at, ss.closure_count, ss.summed_minutes,
       (SELECT ROUND(SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0))::int
        FROM sweep WHERE active_count > 0 AND segment_start < segment_end) AS actual_minutes,
       (SELECT ROUND(SUM(EXTRACT(EPOCH FROM (o.segment_end - o.segment_start)) / 60.0))::int
        FROM observed_segments o WHERE o.hospital_id = ss.hospital_id) AS observed_minutes
FROM selected_stats ss
SQL, $params, $types);

        return false === $event ? null : ['event' => $event, 'children' => $children];
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function eventRow(array $row): ClosureEventRow
    {
        return new ClosureEventRow(
            (string) $row['event_key'],
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            null === $row['source_group_id'] ? null : (string) $row['source_group_id'],
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
            (int) $row['closure_count'],
            (int) $row['summed_minutes'],
            (int) $row['actual_minutes'],
            (int) $row['observed_minutes'],
        );
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function intervalRow(array $row): ClosureIntervalRow
    {
        return new ClosureIntervalRow(
            (int) $row['id'],
            (string) $row['hospital_name'],
            (string) $row['speciality_name'],
            (string) $row['department_name'],
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
            (string) $row['care_level'],
            (string) $row['reason'],
            null === $row['closure_unit'] ? null : (string) $row['closure_unit'],
            null === $row['source_group_id'] ? null : (string) $row['source_group_id'],
            (int) $row['duration_minutes'],
        );
    }
}
