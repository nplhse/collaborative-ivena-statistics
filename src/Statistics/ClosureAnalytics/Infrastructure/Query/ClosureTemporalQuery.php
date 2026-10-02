<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureBreakdownRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationInterval;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoadSnapshot;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureHeatmapCell;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureObservedSegment;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeBucket;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimelineGridCell;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimelineGridRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimelineSegment;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeMetrics;
use Doctrine\DBAL\Connection;

final readonly class ClosureTemporalQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function fetchMetrics(ClosureAnalyticsCriteria $criteria): ClosureTimeMetrics
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $row = $this->connection->fetchAssociative(<<<SQL
WITH {$base}
SELECT
    (SELECT COUNT(*) FROM valid_closures)::int AS closure_count,
    (SELECT COUNT(DISTINCT event_key) FROM valid_closures)::int AS event_count,
    (SELECT COUNT(DISTINCT department_id) FROM valid_closures)::int AS department_count,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (clipped_end - clipped_start)) / 60.0)) FROM valid_closures), 0)::int AS summed_minutes,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0)) FROM observed_segments), 0)::int AS observed_minutes,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (window_end - window_start)) / 60.0)) FROM hospital_windows), 0)::int AS calendar_minutes,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0)) FROM closure_segments), 0)::int AS closed_minutes,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0)) FROM closure_segments WHERE active_count = 1), 0)::int AS single_minutes,
    COALESCE((SELECT ROUND(SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0)) FROM closure_segments WHERE active_count > 1), 0)::int AS multiple_minutes
SQL, $params, $types);

        return new ClosureTimeMetrics(
            (int) ($row['closure_count'] ?? 0),
            (int) ($row['event_count'] ?? 0),
            (int) ($row['department_count'] ?? 0),
            (int) ($row['summed_minutes'] ?? 0),
            (int) ($row['observed_minutes'] ?? 0),
            (int) ($row['calendar_minutes'] ?? 0),
            (int) ($row['closed_minutes'] ?? 0),
            (int) ($row['single_minutes'] ?? 0),
            (int) ($row['multiple_minutes'] ?? 0),
        );
    }

    public function fetchDurationLoad(ClosureAnalyticsCriteria $criteria): ClosureDurationLoadSnapshot
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $row = $this->connection->fetchAssociative(<<<SQL
WITH {$base}
SELECT
    COALESCE((
        SELECT json_agg(json_build_object(
            'hospitalId', v.hospital_id,
            'departmentId', v.department_id,
            'departmentName', d.name,
            'specialityId', v.speciality_id,
            'specialityName', s.name,
            'reason', v.reason,
            'eventKey', v.event_key,
            'start', to_char(v.clipped_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
            'end', to_char(v.clipped_end AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')
        ))
        FROM valid_closures v
        JOIN department d ON d.id = v.department_id
        JOIN speciality s ON s.id = v.speciality_id
    )::text, '[]') AS intervals,
    COALESCE((
        SELECT json_agg(json_build_object(
            'hospitalId', hospital_id,
            'start', to_char(segment_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
            'end', to_char(segment_end AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')
        ))
        FROM observed_segments
    )::text, '[]') AS observed
SQL, $params, $types);

        if (false === $row) {
            return new ClosureDurationLoadSnapshot([], []);
        }

        return new ClosureDurationLoadSnapshot(
            $this->durationIntervals($row['intervals'] ?? '[]'),
            $this->observedSegments($row['observed'] ?? '[]'),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function fetchHospitalChoices(ClosureAnalyticsCriteria $criteria): array
    {
        [$where, $params, $types] = ClosureIntervalSqlFilter::build($criteria->period, $criteria->scope);
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT DISTINCT h.id, h.name
FROM closure_interval ci
JOIN hospital h ON h.id = ci.hospital_id
WHERE {$where}
ORDER BY h.name
SQL, $params, $types);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function fetchDepartmentChoices(ClosureAnalyticsCriteria $criteria): array
    {
        [$where, $params, $types] = ClosureIntervalSqlFilter::build($criteria->period, $criteria->scope);
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT DISTINCT d.id, d.name
FROM closure_interval ci
JOIN department d ON d.id = ci.department_id
WHERE {$where}
ORDER BY d.name
SQL, $params, $types);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function fetchSpecialityChoices(ClosureAnalyticsCriteria $criteria): array
    {
        [$where, $params, $types] = ClosureIntervalSqlFilter::build($criteria->period, $criteria->scope);
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT DISTINCT s.id, s.name
FROM closure_interval ci
JOIN speciality s ON s.id = ci.speciality_id
WHERE {$where}
ORDER BY s.name
SQL, $params, $types);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ], $rows);
    }

    /**
     * @return list<array{value: string, name: string}>
     */
    public function fetchClosureUnitChoices(ClosureAnalyticsCriteria $criteria): array
    {
        if (!\in_array(
            $criteria->filter->scope,
            [StatisticsFilterScope::Hospital, StatisticsFilterScope::MyHospitals],
            true,
        )) {
            return [];
        }

        [$where, $params, $types] = ClosureIntervalSqlFilter::build($criteria->period, $criteria->scope);
        if (StatisticsFilterScope::MyHospitals === $criteria->filter->scope) {
            /** @var list<array{value: string, name: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT DISTINCT
       ci.hospital_id::text || ':' || ci.closure_unit AS value,
       h.name || ' · ' || ci.closure_unit AS name
FROM closure_interval ci
JOIN hospital h ON h.id = ci.hospital_id
WHERE {$where}
  AND ci.closure_unit IS NOT NULL
  AND BTRIM(ci.closure_unit) <> ''
ORDER BY name
SQL, $params, $types);

            return $rows;
        }

        /** @var list<array{value: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT DISTINCT ci.closure_unit AS value, ci.closure_unit AS name
FROM closure_interval ci
WHERE {$where}
  AND ci.closure_unit IS NOT NULL
  AND BTRIM(ci.closure_unit) <> ''
ORDER BY ci.closure_unit
SQL, $params, $types);

        return $rows;
    }

    /**
     * @return list<ClosureTimeBucket>
     */
    public function fetchTimeSeries(ClosureAnalyticsCriteria $criteria): array
    {
        $grain = TimeSeriesGrain::Day === $criteria->timeSeriesGrain ? 'day' : 'month';

        return $this->fetchTimeline($criteria, $grain);
    }

    /**
     * @return list<ClosureTimeBucket>
     */
    public function fetchTimeline(ClosureAnalyticsCriteria $criteria, string $grain): array
    {
        [$step, $format] = match ($grain) {
            'year' => ['1 year', 'YYYY'],
            'quarter' => ['3 months', 'YYYY-"Q"Q'],
            'month' => ['1 month', 'YYYY-MM'],
            'week' => ['1 week', 'IYYY-"W"IW'],
            'day' => ['1 day', 'YYYY-MM-DD'],
            default => throw new \InvalidArgumentException(sprintf('Unknown closure timeline grain "%s".', $grain)),
        };
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
hospital_buckets AS (
    SELECT hw.hospital_id,
           bucket_local AT TIME ZONE 'Europe/Berlin' AS bucket_start,
           (bucket_local + INTERVAL '{$step}') AT TIME ZONE 'Europe/Berlin' AS bucket_end,
           TO_CHAR(bucket_local, '{$format}') AS bucket_key
    FROM hospital_windows hw
    CROSS JOIN LATERAL generate_series(
        DATE_TRUNC('{$grain}', hw.window_start AT TIME ZONE 'Europe/Berlin'),
        DATE_TRUNC('{$grain}', (hw.window_end - INTERVAL '1 microsecond') AT TIME ZONE 'Europe/Berlin'),
        INTERVAL '{$step}'
    ) bucket_local
),
observed_by_bucket AS (
    SELECT b.bucket_key,
           SUM(EXTRACT(EPOCH FROM (LEAST(o.segment_end, b.bucket_end) - GREATEST(o.segment_start, b.bucket_start))) / 60.0) AS minutes
    FROM hospital_buckets b
    JOIN observed_segments o ON o.hospital_id = b.hospital_id
      AND o.segment_start < b.bucket_end AND o.segment_end > b.bucket_start
    GROUP BY b.bucket_key
),
closed_by_bucket AS (
    SELECT b.bucket_key,
           SUM(EXTRACT(EPOCH FROM (LEAST(c.segment_end, b.bucket_end) - GREATEST(c.segment_start, b.bucket_start))) / 60.0) AS closed_minutes,
           SUM(EXTRACT(EPOCH FROM (LEAST(c.segment_end, b.bucket_end) - GREATEST(c.segment_start, b.bucket_start))) / 60.0)
               FILTER (WHERE c.active_count = 1) AS single_minutes,
           SUM(EXTRACT(EPOCH FROM (LEAST(c.segment_end, b.bucket_end) - GREATEST(c.segment_start, b.bucket_start))) / 60.0)
               FILTER (WHERE c.active_count > 1) AS multiple_minutes
    FROM hospital_buckets b
    JOIN closure_segments c ON c.hospital_id = b.hospital_id
      AND c.segment_start < b.bucket_end AND c.segment_end > b.bucket_start
    GROUP BY b.bucket_key
),
counts_by_bucket AS (
    SELECT b.bucket_key, COUNT(DISTINCT v.id)::int AS closure_count
    FROM hospital_buckets b
    JOIN valid_closures v ON v.hospital_id = b.hospital_id
      AND v.clipped_start < b.bucket_end AND v.clipped_end > b.bucket_start
    GROUP BY b.bucket_key
)
SELECT b.bucket_key,
       ROUND(COALESCE(MAX(o.minutes), 0))::int AS observed_minutes,
       ROUND(COALESCE(MAX(c.closed_minutes), 0))::int AS closed_minutes,
       ROUND(COALESCE(MAX(c.single_minutes), 0))::int AS single_minutes,
       ROUND(COALESCE(MAX(c.multiple_minutes), 0))::int AS multiple_minutes,
       COALESCE(MAX(n.closure_count), 0)::int AS closure_count
FROM hospital_buckets b
LEFT JOIN observed_by_bucket o ON o.bucket_key = b.bucket_key
LEFT JOIN closed_by_bucket c ON c.bucket_key = b.bucket_key
LEFT JOIN counts_by_bucket n ON n.bucket_key = b.bucket_key
GROUP BY b.bucket_key
ORDER BY b.bucket_key
SQL, $params, $types);

        return array_map(static fn (array $row): ClosureTimeBucket => new ClosureTimeBucket(
            (string) $row['bucket_key'],
            (int) $row['observed_minutes'],
            (int) $row['closed_minutes'],
            (int) $row['single_minutes'],
            (int) $row['multiple_minutes'],
            (int) $row['closure_count'],
        ), $rows);
    }

    /**
     * Returns one set-based calendar matrix. Closure duration is the union of
     * group events per hospital, while counts retain their explicit meaning.
     *
     * @return list<ClosureTimelineGridRow>
     */
    public function fetchTimelineGrid(ClosureAnalyticsCriteria $criteria, string $grain): array
    {
        [$parentStep, $parentFormat, $childStep, $childFormat] = match ($grain) {
            'year' => ['1 year', 'YYYY', '3 months', 'YYYY-"Q"Q'],
            'quarter' => ['3 months', 'YYYY-"Q"Q', '1 month', 'YYYY-MM'],
            'month' => ['1 month', 'YYYY-MM', '1 week', 'IYYY-"W"IW'],
            'week' => ['1 week', 'IYYY-"W"IW', '1 day', 'YYYY-MM-DD'],
            'day' => ['1 day', 'YYYY-MM-DD', '1 hour', 'YYYY-MM-DD"T"HH24:MI:OF'],
            default => throw new \InvalidArgumentException(sprintf('Unknown closure timeline grain "%s".', $grain)),
        };
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $childOrigin = 'month' === $grain ? "DATE_TRUNC('week', parent_local)" : 'parent_local';
        $childSeries = 'day' === $grain
            ? <<<'SQL'
                generate_series(
                    parent_start,
                    parent_end - INTERVAL '1 microsecond',
                    INTERVAL '1 hour'
                ) cell_start
                SQL
            : <<<SQL
                generate_series(
                    {$childOrigin},
                    parent_local + INTERVAL '{$parentStep}' - INTERVAL '1 microsecond',
                    INTERVAL '{$childStep}'
                ) cell_local
                SQL;
        $cellColumns = 'day' === $grain
            ? "cell_start, LEAST(cell_start + INTERVAL '{$childStep}', parent_end) AS cell_end, TO_CHAR(cell_start AT TIME ZONE 'Europe/Berlin', '{$childFormat}') AS cell_key"
            : "GREATEST(cell_local AT TIME ZONE 'Europe/Berlin', parent_start) AS cell_start, LEAST((cell_local + INTERVAL '{$childStep}') AT TIME ZONE 'Europe/Berlin', parent_end) AS cell_end, TO_CHAR(cell_local, '{$childFormat}') AS cell_key";

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
hospital_parents AS (
    SELECT hw.hospital_id,
           parent_local,
           parent_local AT TIME ZONE 'Europe/Berlin' AS parent_start,
           (parent_local + INTERVAL '{$parentStep}') AT TIME ZONE 'Europe/Berlin' AS parent_end,
           TO_CHAR(parent_local, '{$parentFormat}') AS parent_key
    FROM hospital_windows hw
    CROSS JOIN LATERAL generate_series(
        DATE_TRUNC('{$grain}', hw.window_start AT TIME ZONE 'Europe/Berlin'),
        DATE_TRUNC('{$grain}', (hw.window_end - INTERVAL '1 microsecond') AT TIME ZONE 'Europe/Berlin'),
        INTERVAL '{$parentStep}'
    ) parent_local
),
hospital_cells AS (
    SELECT hp.*, {$cellColumns}
    FROM hospital_parents hp
    CROSS JOIN LATERAL {$childSeries}
),
calendar_cells AS (
    SELECT DISTINCT parent_key, parent_start, parent_end, cell_key, cell_start, cell_end
    FROM hospital_cells
),
closed_by_cell AS (
    SELECT hc.parent_key, hc.cell_key, hc.cell_start,
           SUM(EXTRACT(EPOCH FROM (LEAST(c.segment_end, hc.cell_end) - GREATEST(c.segment_start, hc.cell_start))) / 60.0) AS closed_minutes
    FROM hospital_cells hc
    JOIN closure_segments c ON c.hospital_id = hc.hospital_id
      AND c.segment_start < hc.cell_end AND c.segment_end > hc.cell_start
    GROUP BY hc.parent_key, hc.cell_key, hc.cell_start
),
counts_by_cell AS (
    SELECT hc.parent_key, hc.cell_key, hc.cell_start,
           COUNT(DISTINCT v.id)::int AS closure_count,
           COUNT(DISTINCT v.event_key)::int AS event_count
    FROM hospital_cells hc
    JOIN valid_closures v ON v.hospital_id = hc.hospital_id
      AND v.clipped_start < hc.cell_end AND v.clipped_end > hc.cell_start
    GROUP BY hc.parent_key, hc.cell_key, hc.cell_start
)
SELECT c.parent_key, c.parent_start, c.parent_end, c.cell_key, c.cell_start, c.cell_end,
       ROUND(COALESCE(d.closed_minutes, 0))::int AS closed_minutes,
       COALESCE(n.closure_count, 0)::int AS closure_count,
       COALESCE(n.event_count, 0)::int AS event_count
FROM calendar_cells c
LEFT JOIN closed_by_cell d
  ON d.parent_key = c.parent_key AND d.cell_key = c.cell_key AND d.cell_start = c.cell_start
LEFT JOIN counts_by_cell n
  ON n.parent_key = c.parent_key AND n.cell_key = c.cell_key AND n.cell_start = c.cell_start
ORDER BY c.parent_start, c.cell_start
SQL, $params, $types);

        $matrix = [];
        foreach ($rows as $row) {
            $parentKey = (string) $row['parent_key'];
            $matrix[$parentKey] ??= [
                'start' => new \DateTimeImmutable((string) $row['parent_start']),
                'end' => new \DateTimeImmutable((string) $row['parent_end']),
                'cells' => [],
            ];
            $matrix[$parentKey]['cells'][] = new ClosureTimelineGridCell(
                (string) $row['cell_key'],
                new \DateTimeImmutable((string) $row['cell_start']),
                new \DateTimeImmutable((string) $row['cell_end']),
                (int) $row['closed_minutes'],
                (int) $row['closure_count'],
                (int) $row['event_count'],
            );
        }

        return array_map(
            static fn (string $key, array $row): ClosureTimelineGridRow => new ClosureTimelineGridRow(
                $key,
                $row['start'],
                $row['end'],
                $row['cells'],
            ),
            array_keys($matrix),
            array_values($matrix),
        );
    }

    /**
     * Exact interval segments split at local day boundaries for proportional rendering.
     *
     * @return list<ClosureTimelineSegment>
     */
    public function fetchTimelineSegments(ClosureAnalyticsCriteria $criteria): array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
day_segments AS (
    SELECT v.*,
           day_local,
           GREATEST(v.clipped_start, day_local AT TIME ZONE 'Europe/Berlin') AS segment_start,
           LEAST(v.clipped_end, (day_local + INTERVAL '1 day') AT TIME ZONE 'Europe/Berlin') AS segment_end
    FROM valid_closures v
    CROSS JOIN LATERAL generate_series(
        DATE_TRUNC('day', v.clipped_start AT TIME ZONE 'Europe/Berlin'),
        DATE_TRUNC('day', (v.clipped_end - INTERVAL '1 microsecond') AT TIME ZONE 'Europe/Berlin'),
        INTERVAL '1 day'
    ) day_local
)
SELECT TO_CHAR(ds.day_local, 'YYYY-MM-DD') AS day_key,
       ds.hospital_id, h.name AS hospital_name, s.name AS speciality_name,
       ds.care_level, d.name AS department_name, ds.event_key,
       ds.event_type,
       NULLIF(BTRIM(ds.source_group_id), '') AS source_group_id,
       ds.id AS interval_id, ds.segment_start, ds.segment_end,
       EXISTS (
           SELECT 1 FROM day_segments other
           WHERE other.hospital_id = ds.hospital_id
             AND other.event_key <> ds.event_key
             AND other.segment_start < ds.segment_end
             AND other.segment_end > ds.segment_start
       ) AS is_parallel
FROM day_segments ds
JOIN hospital h ON h.id = ds.hospital_id
JOIN speciality s ON s.id = ds.speciality_id
JOIN department d ON d.id = ds.department_id
WHERE ds.segment_start < ds.segment_end
ORDER BY ds.day_local, h.name, s.name, d.name, ds.care_level, ds.segment_start, ds.id
SQL, $params, $types);

        return array_map(static fn (array $row): ClosureTimelineSegment => new ClosureTimelineSegment(
            (string) $row['day_key'],
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            (string) $row['speciality_name'],
            (string) $row['care_level'],
            (string) $row['department_name'],
            (string) $row['event_key'],
            ClosureEventType::from((string) $row['event_type']),
            null === $row['source_group_id'] ? null : (string) $row['source_group_id'],
            (int) $row['interval_id'],
            new \DateTimeImmutable((string) $row['segment_start']),
            new \DateTimeImmutable((string) $row['segment_end']),
            \in_array($row['is_parallel'], [true, 1, '1', 't', 'true'], true),
        ), $rows);
    }

    /**
     * @return list<ClosureHeatmapCell>
     */
    public function fetchHeatmap(ClosureAnalyticsCriteria $criteria): array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
observed_cells AS (
    SELECT EXTRACT(ISODOW FROM hour_start AT TIME ZONE 'Europe/Berlin')::int AS weekday,
           FLOOR(EXTRACT(HOUR FROM hour_start AT TIME ZONE 'Europe/Berlin') / 2)::int AS slot,
           SUM(EXTRACT(EPOCH FROM (LEAST(o.segment_end, hour_start + INTERVAL '1 hour') - GREATEST(o.segment_start, hour_start))) / 60.0) AS minutes
    FROM observed_segments o
    CROSS JOIN LATERAL generate_series(
        DATE_TRUNC('hour', o.segment_start),
        DATE_TRUNC('hour', o.segment_end - INTERVAL '1 microsecond'),
        INTERVAL '1 hour'
    ) hour_start
    GROUP BY weekday, slot
),
closed_cells AS (
    SELECT EXTRACT(ISODOW FROM hour_start AT TIME ZONE 'Europe/Berlin')::int AS weekday,
           FLOOR(EXTRACT(HOUR FROM hour_start AT TIME ZONE 'Europe/Berlin') / 2)::int AS slot,
           SUM(EXTRACT(EPOCH FROM (LEAST(c.segment_end, hour_start + INTERVAL '1 hour') - GREATEST(c.segment_start, hour_start))) / 60.0) AS minutes
    FROM closure_segments c
    CROSS JOIN LATERAL generate_series(
        DATE_TRUNC('hour', c.segment_start),
        DATE_TRUNC('hour', c.segment_end - INTERVAL '1 microsecond'),
        INTERVAL '1 hour'
    ) hour_start
    GROUP BY weekday, slot
)
SELECT o.weekday, o.slot, ROUND(o.minutes)::int AS observed_minutes,
       ROUND(COALESCE(c.minutes, 0))::int AS closed_minutes
FROM observed_cells o
LEFT JOIN closed_cells c ON c.weekday = o.weekday AND c.slot = o.slot
ORDER BY o.weekday, o.slot
SQL, $params, $types);

        return array_map(static fn (array $row): ClosureHeatmapCell => new ClosureHeatmapCell(
            (int) $row['weekday'],
            (int) $row['slot'],
            (int) $row['observed_minutes'],
            (int) $row['closed_minutes'],
        ), $rows);
    }

    /**
     * @return list<ClosureBreakdownRow>
     */
    public function fetchBreakdown(ClosureAnalyticsCriteria $criteria, string $kind): array
    {
        if ('event_type' === $kind) {
            return $this->fetchEventTypeBreakdown($criteria);
        }

        $hospitalScope = StatisticsFilterScope::Hospital === $criteria->filter->scope;
        [$key, $name, $join, $extraWhere] = match ($kind) {
            'hospital' => ['v.hospital_id::text', 'h.name', 'JOIN hospital h ON h.id = v.hospital_id', 'TRUE'],
            'speciality' => ['v.speciality_id::text', 's.name', 'JOIN speciality s ON s.id = v.speciality_id', 'TRUE'],
            'department' => ['v.department_id::text', 'd.name', 'JOIN department d ON d.id = v.department_id', 'TRUE'],
            'care_level' => ['v.care_level', 'v.care_level', '', 'TRUE'],
            'reason' => ['v.reason', 'v.reason', '', 'TRUE'],
            'closure_unit' => $hospitalScope
                ? [
                    'v.closure_unit',
                    "h.name || ' · ' || v.closure_unit",
                    'JOIN hospital h ON h.id = v.hospital_id',
                    "v.closure_unit IS NOT NULL AND BTRIM(v.closure_unit) <> ''",
                ]
                : [
                    "v.hospital_id::text || ':' || v.closure_unit",
                    "h.name || ' · ' || v.closure_unit",
                    'JOIN hospital h ON h.id = v.hospital_id',
                    "v.closure_unit IS NOT NULL AND BTRIM(v.closure_unit) <> ''",
                ],
            default => throw new \InvalidArgumentException(sprintf('Unknown closure breakdown "%s".', $kind)),
        };
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $orderBy = \in_array($kind, ['care_level', 'reason'], true) ? 'closure_count' : 'actual_minutes';

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
dimension_intervals AS (
    SELECT {$key} AS dimension_key, {$name} AS dimension_name, v.*
    FROM valid_closures v
    {$join}
    WHERE {$extraWhere}
),
dimension_points_raw AS (
    SELECT dimension_key, hospital_id, clipped_start AS point, 1 AS delta FROM dimension_intervals
    UNION ALL
    SELECT dimension_key, hospital_id, clipped_end AS point, -1 AS delta FROM dimension_intervals
),
dimension_points AS (
    SELECT dimension_key, hospital_id, point, SUM(delta) AS delta
    FROM dimension_points_raw GROUP BY dimension_key, hospital_id, point
),
dimension_sweep AS (
    SELECT dimension_key, hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY dimension_key, hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (PARTITION BY dimension_key, hospital_id ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM dimension_points
),
dimension_actual AS (
    SELECT dimension_key,
           SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0) AS actual_minutes
    FROM dimension_sweep
    WHERE active_count > 0 AND segment_start < segment_end
    GROUP BY dimension_key
),
dimension_hospitals AS (
    SELECT DISTINCT dimension_key, hospital_id FROM dimension_intervals
),
dimension_observed AS (
    SELECT dh.dimension_key,
           SUM(EXTRACT(EPOCH FROM (o.segment_end - o.segment_start)) / 60.0) AS observed_minutes
    FROM dimension_hospitals dh
    JOIN observed_segments o ON o.hospital_id = dh.hospital_id
    GROUP BY dh.dimension_key
),
dimension_totals AS (
    SELECT dimension_key, MIN(dimension_name) AS dimension_name, COUNT(*)::int AS closure_count,
           SUM(EXTRACT(EPOCH FROM (clipped_end - clipped_start)) / 60.0) AS summed_minutes
    FROM dimension_intervals
    GROUP BY dimension_key
)
SELECT t.dimension_key, t.dimension_name, t.closure_count,
       ROUND(t.summed_minutes)::int AS summed_minutes,
       ROUND(a.actual_minutes)::int AS actual_minutes,
       ROUND(o.observed_minutes)::int AS observed_minutes
FROM dimension_totals t
JOIN dimension_actual a USING (dimension_key)
JOIN dimension_observed o USING (dimension_key)
ORDER BY {$orderBy} DESC, dimension_name
SQL, $params, $types);

        return array_map(static fn (array $row): ClosureBreakdownRow => new ClosureBreakdownRow(
            (string) $row['dimension_key'],
            (string) $row['dimension_name'],
            (int) $row['closure_count'],
            (int) $row['summed_minutes'],
            (int) $row['actual_minutes'],
            (int) $row['observed_minutes'],
        ), $rows);
    }

    /**
     * @return list<ClosureBreakdownRow>
     */
    private function fetchEventTypeBreakdown(ClosureAnalyticsCriteria $criteria): array
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
dimension_intervals AS (
    SELECT v.event_type AS dimension_key, v.*
    FROM valid_closures v
),
dimension_points_raw AS (
    SELECT dimension_key, hospital_id, clipped_start AS point, 1 AS delta FROM dimension_intervals
    UNION ALL
    SELECT dimension_key, hospital_id, clipped_end AS point, -1 AS delta FROM dimension_intervals
),
dimension_points AS (
    SELECT dimension_key, hospital_id, point, SUM(delta) AS delta
    FROM dimension_points_raw GROUP BY dimension_key, hospital_id, point
),
dimension_sweep AS (
    SELECT dimension_key, hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY dimension_key, hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (PARTITION BY dimension_key, hospital_id ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM dimension_points
),
dimension_actual AS (
    SELECT dimension_key,
           SUM(EXTRACT(EPOCH FROM (segment_end - segment_start)) / 60.0) AS actual_minutes
    FROM dimension_sweep
    WHERE active_count > 0 AND segment_start < segment_end
    GROUP BY dimension_key
),
dimension_hospitals AS (
    SELECT DISTINCT dimension_key, hospital_id FROM dimension_intervals
),
dimension_observed AS (
    SELECT dh.dimension_key,
           SUM(EXTRACT(EPOCH FROM (o.segment_end - o.segment_start)) / 60.0) AS observed_minutes
    FROM dimension_hospitals dh
    JOIN observed_segments o ON o.hospital_id = dh.hospital_id
    GROUP BY dh.dimension_key
),
dimension_totals AS (
    SELECT dimension_key, COUNT(DISTINCT event_key)::int AS closure_count,
           SUM(EXTRACT(EPOCH FROM (clipped_end - clipped_start)) / 60.0) AS summed_minutes
    FROM dimension_intervals
    GROUP BY dimension_key
),
type_keys AS (
    SELECT unnest(ARRAY['group', 'cluster', 'single']) AS dimension_key
)
SELECT k.dimension_key, k.dimension_key AS dimension_name,
       COALESCE(t.closure_count, 0)::int AS closure_count,
       ROUND(COALESCE(t.summed_minutes, 0))::int AS summed_minutes,
       ROUND(COALESCE(a.actual_minutes, 0))::int AS actual_minutes,
       ROUND(COALESCE(o.observed_minutes, 0))::int AS observed_minutes
FROM type_keys k
LEFT JOIN dimension_totals t USING (dimension_key)
LEFT JOIN dimension_actual a USING (dimension_key)
LEFT JOIN dimension_observed o USING (dimension_key)
ORDER BY array_position(ARRAY['group', 'cluster', 'single'], k.dimension_key)
SQL, $params, $types);

        return array_map(static fn (array $row): ClosureBreakdownRow => new ClosureBreakdownRow(
            (string) $row['dimension_key'],
            (string) $row['dimension_name'],
            (int) $row['closure_count'],
            (int) $row['summed_minutes'],
            (int) $row['actual_minutes'],
            (int) $row['observed_minutes'],
        ), $rows);
    }

    /**
     * @return list<ClosureDurationInterval>
     */
    private function durationIntervals(mixed $json): array
    {
        $intervals = [];
        foreach ($this->jsonRows($json) as $row) {
            $start = $this->utcTimestamp($row['start'] ?? null);
            $end = $this->utcTimestamp($row['end'] ?? null);
            if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable || $end <= $start) {
                continue;
            }
            $reason = $row['reason'] ?? null;
            $intervals[] = new ClosureDurationInterval(
                (int) ($row['hospitalId'] ?? 0),
                (int) ($row['departmentId'] ?? 0),
                (string) ($row['departmentName'] ?? ''),
                \is_string($reason) && '' !== $reason ? $reason : null,
                (string) ($row['eventKey'] ?? ''),
                $start,
                $end,
                (int) ($row['specialityId'] ?? 0),
                (string) ($row['specialityName'] ?? ''),
            );
        }

        return $intervals;
    }

    /**
     * @return list<ClosureObservedSegment>
     */
    private function observedSegments(mixed $json): array
    {
        $segments = [];
        foreach ($this->jsonRows($json) as $row) {
            $start = $this->utcTimestamp($row['start'] ?? null);
            $end = $this->utcTimestamp($row['end'] ?? null);
            if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable || $end <= $start) {
                continue;
            }
            $segments[] = new ClosureObservedSegment((int) ($row['hospitalId'] ?? 0), $start, $end);
        }

        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonRows(mixed $json): array
    {
        if (\is_string($json)) {
            $decoded = json_decode($json, true);
        } elseif (\is_array($json)) {
            $decoded = $json;
        } else {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function utcTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable ? $parsed : null;
    }
}
