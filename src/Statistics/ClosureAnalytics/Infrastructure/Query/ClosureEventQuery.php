<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventChildPreview;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalTableRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureSameDayInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureEventQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<ClosureEventRow>
     */
    public function fetchEvents(
        ClosureAnalyticsCriteria $criteria,
        int $offset,
        int $limit,
        string $sortBy,
        string $orderBy,
    ): array {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $sortExpression = $this->sortExpression($sortBy);
        $direction = 'asc' === strtolower($orderBy) ? 'ASC' : 'DESC';

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
    SELECT v.event_key, MIN(v.event_type) AS event_type, v.hospital_id,
           NULLIF(BTRIM(MIN(v.source_group_id)), '') AS source_group_id,
           MIN(v.clipped_start) AS starts_at, MAX(v.clipped_end) AS ends_at,
           COUNT(*)::int AS closure_count,
           SUM(EXTRACT(EPOCH FROM (v.clipped_end - v.clipped_start)) / 60.0) AS summed_minutes,
           JSONB_AGG(JSONB_BUILD_OBJECT(
               'id', v.id,
               'speciality', s.name,
               'department', d.name,
               'careLevel', v.care_level,
               'reason', v.reason,
               'closureUnit', v.closure_unit,
               'startsAt', v.clipped_start,
               'endsAt', v.clipped_end
           ) ORDER BY v.clipped_start, v.id) AS children
    FROM valid_closures v
    JOIN speciality s ON s.id = v.speciality_id
    JOIN department d ON d.id = v.department_id
    GROUP BY v.event_key, v.hospital_id
)
SELECT e.event_key, e.event_type, e.hospital_id, h.name AS hospital_name, e.source_group_id,
       e.starts_at, e.ends_at, e.closure_count,
       ROUND(e.summed_minutes)::int AS summed_minutes,
       ROUND(a.actual_minutes)::int AS actual_minutes,
       ROUND(o.observed_minutes)::int AS observed_minutes,
       e.children::text AS children
FROM event_totals e
JOIN listed_event_actual a USING (event_key)
JOIN hospital_observed o ON o.hospital_id = e.hospital_id
JOIN hospital h ON h.id = e.hospital_id
ORDER BY {$sortExpression} {$direction} NULLS LAST, e.event_key ASC
LIMIT :limit OFFSET :offset
SQL, [...$params, 'limit' => max(1, $limit), 'offset' => max(0, $offset)], [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        return array_map($this->eventRow(...), $rows);
    }

    public function countEvents(ClosureAnalyticsCriteria $criteria): int
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        return (int) $this->connection->fetchOne(<<<SQL
WITH {$base}
SELECT COUNT(DISTINCT event_key) FROM valid_closures
SQL, $params, $types);
    }

    /**
     * @return list<ClosureIntervalTableRow>
     */
    public function fetchIntervals(
        ClosureAnalyticsCriteria $criteria,
        int $offset,
        int $limit,
        string $sortBy,
        string $orderBy,
    ): array {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $sortExpression = $this->intervalSortExpression($sortBy);
        $direction = 'asc' === strtolower($orderBy) ? 'ASC' : 'DESC';

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT v.id, v.event_key, v.event_type, h.name AS hospital_name,
       s.name AS speciality_name, d.name AS department_name,
       v.clipped_start AS starts_at, v.clipped_end AS ends_at,
       v.care_level, v.reason, v.closure_unit,
       NULLIF(BTRIM(v.source_group_id), '') AS source_group_id,
       ROUND(EXTRACT(EPOCH FROM (v.clipped_end - v.clipped_start)) / 60.0)::int AS duration_minutes
FROM valid_closures v
JOIN hospital h ON h.id = v.hospital_id
JOIN speciality s ON s.id = v.speciality_id
JOIN department d ON d.id = v.department_id
ORDER BY {$sortExpression} {$direction} NULLS LAST, v.id ASC
LIMIT :limit OFFSET :offset
SQL, [...$params, 'limit' => max(1, $limit), 'offset' => max(0, $offset)], [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        return array_map($this->intervalTableRow(...), $rows);
    }

    public function countIntervals(ClosureAnalyticsCriteria $criteria): int
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        return (int) $this->connection->fetchOne(<<<SQL
WITH {$base}
SELECT COUNT(*) FROM valid_closures
SQL, $params, $types);
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
SELECT v.id, v.hospital_id, h.name AS hospital_name, s.name AS speciality_name, d.name AS department_name,
       v.clipped_start AS starts_at, v.clipped_end AS ends_at, v.care_level, v.reason,
       v.closure_unit, NULLIF(BTRIM(v.source_group_id), '') AS source_group_id,
       ROUND(EXTRACT(EPOCH FROM (v.clipped_end - v.clipped_start)) / 60.0)::int AS duration_minutes,
       v.event_key, v.event_type, v.department_id
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
    SELECT MIN(s.event_type) AS event_type, MIN(s.hospital_id)::int AS hospital_id, MIN(h.name) AS hospital_name,
           NULLIF(BTRIM(MIN(s.source_group_id)), '') AS source_group_id,
           MIN(s.clipped_start) AS starts_at, MAX(s.clipped_end) AS ends_at,
           COUNT(*)::int AS closure_count,
           ROUND(SUM(EXTRACT(EPOCH FROM (s.clipped_end - s.clipped_start)) / 60.0))::int AS summed_minutes
    FROM selected s JOIN hospital h ON h.id = s.hospital_id
)
SELECT :event_key AS event_key, ss.event_type, ss.hospital_id, ss.hospital_name, ss.source_group_id,
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
     * @return list<ClosureSameDayInterval>
     */
    public function fetchSameDayDepartmentIntervals(ClosureAnalyticsCriteria $criteria, string $excludeEventKey): array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $params['exclude_event_key'] = $excludeEventKey;
        $types['exclude_event_key'] = ParameterType::STRING;

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT v.event_key, v.event_type, d.name AS department_name, s.name AS speciality_name, v.care_level,
       v.clipped_start AS starts_at, v.clipped_end AS ends_at
FROM valid_closures v
JOIN speciality s ON s.id = v.speciality_id
JOIN department d ON d.id = v.department_id
WHERE v.event_key <> :exclude_event_key
ORDER BY v.clipped_start, v.event_key, v.id
SQL, $params, $types);

        return array_map(
            static fn (array $row): ClosureSameDayInterval => new ClosureSameDayInterval(
                (string) $row['event_key'],
                ClosureEventType::from((string) $row['event_type']),
                (string) $row['department_name'],
                (string) $row['speciality_name'],
                (string) $row['care_level'],
                new \DateTimeImmutable((string) $row['starts_at']),
                new \DateTimeImmutable((string) $row['ends_at']),
            ),
            $rows,
        );
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function eventRow(array $row): ClosureEventRow
    {
        return new ClosureEventRow(
            (string) $row['event_key'],
            ClosureEventType::from((string) $row['event_type']),
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            null === $row['source_group_id'] ? null : (string) $row['source_group_id'],
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
            (int) $row['closure_count'],
            (int) $row['summed_minutes'],
            (int) $row['actual_minutes'],
            (int) $row['observed_minutes'],
            $this->eventChildren($row['children'] ?? null),
        );
    }

    private function sortExpression(string $sortBy): string
    {
        return match ($sortBy) {
            'endsAt' => 'e.ends_at',
            'hospital' => 'h.name',
            'event' => 'e.source_group_id',
            'closureCount' => 'e.closure_count',
            'summedMinutes' => 'e.summed_minutes',
            'actualMinutes' => 'a.actual_minutes',
            default => 'e.starts_at',
        };
    }

    private function intervalSortExpression(string $sortBy): string
    {
        return match ($sortBy) {
            'endsAt' => 'v.clipped_end',
            'hospital' => 'h.name',
            'speciality' => 's.name',
            'department' => 'd.name',
            'careLevel' => 'v.care_level',
            'reason' => 'v.reason',
            'closureUnit' => 'v.closure_unit',
            'durationMinutes' => 'duration_minutes',
            default => 'v.clipped_start',
        };
    }

    /**
     * @return list<ClosureEventChildPreview>
     */
    private function eventChildren(int|string|null $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $children = [];
        foreach ($decoded as $child) {
            if (!\is_array($child)) {
                continue;
            }

            $children[] = new ClosureEventChildPreview(
                (int) ($child['id'] ?? 0),
                (string) ($child['speciality'] ?? ''),
                (string) ($child['department'] ?? ''),
                (string) ($child['careLevel'] ?? ''),
                (string) ($child['reason'] ?? ''),
                isset($child['closureUnit']) ? (string) $child['closureUnit'] : null,
                new \DateTimeImmutable((string) ($child['startsAt'] ?? 'now')),
                new \DateTimeImmutable((string) ($child['endsAt'] ?? 'now')),
            );
        }

        return $children;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function intervalRow(array $row): ClosureIntervalRow
    {
        return new ClosureIntervalRow(
            (int) $row['id'],
            (int) $row['hospital_id'],
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
            (string) $row['event_key'],
            ClosureEventType::from((string) $row['event_type']),
            (int) $row['department_id'],
        );
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function intervalTableRow(array $row): ClosureIntervalTableRow
    {
        return new ClosureIntervalTableRow(
            (int) $row['id'],
            (string) $row['event_key'],
            ClosureEventType::from((string) $row['event_type']),
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
