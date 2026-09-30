<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;

final class ClosureTemporalSql
{
    private const string TIMEZONE = 'Europe/Berlin';

    /**
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    public static function base(ClosureAnalyticsCriteria $criteria): array
    {
        [$scopeWhere, $params, $types] = ClosureIntervalSqlFilter::buildScope($criteria->scope);
        $fromUtc = $criteria->period->from instanceof \DateTimeImmutable
            ? "(CAST(:period_from AS timestamp) AT TIME ZONE '".self::TIMEZONE."')"
            : 'NULL::timestamptz';
        $toUtc = $criteria->period->toExclusive instanceof \DateTimeImmutable
            ? "(CAST(:period_to AS timestamp) AT TIME ZONE '".self::TIMEZONE."')"
            : 'NULL::timestamptz';

        if ($criteria->period->from instanceof \DateTimeImmutable) {
            $params['period_from'] = $criteria->period->from;
            $types['period_from'] = Types::DATETIME_IMMUTABLE;
        }
        if ($criteria->period->toExclusive instanceof \DateTimeImmutable) {
            $params['period_to'] = $criteria->period->toExclusive;
            $types['period_to'] = Types::DATETIME_IMMUTABLE;
        }

        $periodOverlap = [];
        if ($criteria->period->from instanceof \DateTimeImmutable) {
            $periodOverlap[] = 'ci.ends_at > :period_from';
        }
        if ($criteria->period->toExclusive instanceof \DateTimeImmutable) {
            $periodOverlap[] = 'ci.starts_at < :period_to';
        }
        $periodWhere = [] === $periodOverlap ? 'TRUE' : implode(' AND ', $periodOverlap);
        $closureFilters = [];
        if ([] !== $criteria->hospitalIds) {
            $closureFilters[] = 'ci.hospital_id IN (:closure_hospital_ids)';
            $params['closure_hospital_ids'] = $criteria->hospitalIds;
            $types['closure_hospital_ids'] = ArrayParameterType::INTEGER;
        }
        if ([] !== $criteria->departmentIds) {
            $closureFilters[] = 'ci.department_id IN (:closure_department_ids)';
            $params['closure_department_ids'] = $criteria->departmentIds;
            $types['closure_department_ids'] = ArrayParameterType::INTEGER;
        }
        if ([] !== $criteria->specialityIds) {
            $closureFilters[] = 'ci.speciality_id IN (:closure_speciality_ids)';
            $params['closure_speciality_ids'] = $criteria->specialityIds;
            $types['closure_speciality_ids'] = ArrayParameterType::INTEGER;
        }
        if ([] !== $criteria->careLevels) {
            $closureFilters[] = 'ci.care_level IN (:closure_care_levels)';
            $params['closure_care_levels'] = $criteria->careLevels;
            $types['closure_care_levels'] = ArrayParameterType::STRING;
        }
        if ([] !== $criteria->reasons) {
            $closureFilters[] = 'ci.reason IN (:closure_reasons)';
            $params['closure_reasons'] = $criteria->reasons;
            $types['closure_reasons'] = ArrayParameterType::STRING;
        }
        if ([] !== $criteria->closureUnits) {
            $closureFilters[] = StatisticsFilterScope::MyHospitals === $criteria->filter->scope
                ? "(ci.hospital_id::text || ':' || ci.closure_unit) IN (:closure_units)"
                : 'ci.closure_unit IN (:closure_units)';
            $params['closure_units'] = $criteria->closureUnits;
            $types['closure_units'] = ArrayParameterType::STRING;
        }
        $closureWhere = [] === $closureFilters ? 'TRUE' : implode(' AND ', $closureFilters);
        $eventTypeWhere = 'TRUE';
        if ([] !== $criteria->eventTypes) {
            $eventTypeWhere = 'event_type IN (:closure_event_types)';
            $params['closure_event_types'] = $criteria->eventTypes;
            $types['closure_event_types'] = ArrayParameterType::STRING;
        }

        $sql = <<<SQL
raw_scoped AS (
    SELECT ci.*,
           ci.starts_at AT TIME ZONE 'Europe/Berlin' AS starts_utc,
           ci.ends_at AT TIME ZONE 'Europe/Berlin' AS ends_utc
    FROM closure_interval ci
    WHERE {$scopeWhere}
),
ranked_intervals AS (
    SELECT r.*,
           ROW_NUMBER() OVER (
               PARTITION BY hospital_id, speciality_id, department_id, starts_at, ends_at,
                            care_level, reason, facility_kind, COALESCE(closure_unit, ''),
                            COALESCE(source_group_id, '')
               ORDER BY source_changed_at DESC, import_id DESC, id DESC
           ) AS canonical_rank
    FROM raw_scoped r
),
canonical_scoped_intervals AS (
    SELECT *
    FROM ranked_intervals ci
    WHERE canonical_rank = 1
),
identified_intervals AS (
    SELECT ci.*,
           COUNT(*) FILTER (
               WHERE NULLIF(BTRIM(ci.source_group_id), '') IS NULL
           ) OVER (
               PARTITION BY ci.hospital_id, ci.starts_at, ci.ends_at
           ) AS coincident_ungrouped_count
    FROM canonical_scoped_intervals ci
),
canonical_intervals AS (
    SELECT *
    FROM identified_intervals ci
    WHERE {$periodWhere} AND {$closureWhere}
),
closure_clipped AS (
    SELECT ci.*,
           GREATEST(ci.starts_utc, COALESCE({$fromUtc}, ci.starts_utc)) AS clipped_start,
           LEAST(ci.ends_utc, COALESCE({$toUtc}, ci.ends_utc)) AS clipped_end,
           CASE
               WHEN NULLIF(BTRIM(ci.source_group_id), '') IS NOT NULL
                   THEN 'group:' || ci.hospital_id::text || ':' || BTRIM(ci.source_group_id)
               WHEN ci.coincident_ungrouped_count > 1
                   THEN 'cluster:' || ci.hospital_id::text || ':' || MD5(
                       TO_CHAR(ci.starts_at, 'YYYY-MM-DD"T"HH24:MI:SS.US')
                       || '|' ||
                       TO_CHAR(ci.ends_at, 'YYYY-MM-DD"T"HH24:MI:SS.US')
                   )
               ELSE 'interval:' || ci.id::text
           END AS event_key,
           CASE
               WHEN NULLIF(BTRIM(ci.source_group_id), '') IS NOT NULL THEN 'group'
               WHEN ci.coincident_ungrouped_count > 1 THEN 'cluster'
               ELSE 'single'
           END AS event_type
    FROM canonical_intervals ci
),
valid_closures AS (
    SELECT *
    FROM closure_clipped
    WHERE clipped_start < clipped_end AND {$eventTypeWhere}
),
import_spans AS (
    SELECT import_id, hospital_id, MIN(starts_utc) AS span_start, MAX(ends_utc) AS span_end
    FROM raw_scoped
    GROUP BY import_id, hospital_id
),
coverage_clipped AS (
    SELECT hospital_id,
           GREATEST(span_start, COALESCE({$fromUtc}, span_start)) AS clipped_start,
           LEAST(span_end, COALESCE({$toUtc}, span_end)) AS clipped_end
    FROM import_spans
    WHERE ({$fromUtc} IS NULL OR span_end > {$fromUtc})
      AND ({$toUtc} IS NULL OR span_start < {$toUtc})
),
valid_coverage AS (
    SELECT * FROM coverage_clipped WHERE clipped_start < clipped_end
),
coverage_point_deltas AS (
    SELECT hospital_id, clipped_start AS point, 1 AS delta FROM valid_coverage
    UNION ALL
    SELECT hospital_id, clipped_end AS point, -1 AS delta FROM valid_coverage
),
coverage_points AS (
    SELECT hospital_id, point, SUM(delta) AS delta
    FROM coverage_point_deltas
    GROUP BY hospital_id, point
),
coverage_sweep AS (
    SELECT hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (PARTITION BY hospital_id ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM coverage_points
),
observed_segments AS (
    SELECT hospital_id, segment_start, segment_end
    FROM coverage_sweep
    WHERE active_count > 0 AND segment_start < segment_end
),
event_point_deltas AS (
    SELECT event_key, hospital_id, clipped_start AS point, 1 AS delta FROM valid_closures
    UNION ALL
    SELECT event_key, hospital_id, clipped_end AS point, -1 AS delta FROM valid_closures
),
event_points AS (
    SELECT event_key, hospital_id, point, SUM(delta) AS delta
    FROM event_point_deltas
    GROUP BY event_key, hospital_id, point
),
event_sweep AS (
    SELECT event_key, hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY event_key, hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (
               PARTITION BY event_key, hospital_id
               ORDER BY point ROWS UNBOUNDED PRECEDING
           ) AS active_count
    FROM event_points
),
event_segments AS (
    SELECT event_key, hospital_id, segment_start, segment_end
    FROM event_sweep
    WHERE active_count > 0 AND segment_start < segment_end
),
closure_point_deltas AS (
    SELECT hospital_id, segment_start AS point, 1 AS delta FROM event_segments
    UNION ALL
    SELECT hospital_id, segment_end AS point, -1 AS delta FROM event_segments
),
closure_points AS (
    SELECT hospital_id, point, SUM(delta) AS delta
    FROM closure_point_deltas
    GROUP BY hospital_id, point
),
closure_sweep AS (
    SELECT hospital_id, point AS segment_start,
           LEAD(point) OVER (PARTITION BY hospital_id ORDER BY point) AS segment_end,
           SUM(delta) OVER (PARTITION BY hospital_id ORDER BY point ROWS UNBOUNDED PRECEDING) AS active_count
    FROM closure_points
),
closure_segments AS (
    SELECT hospital_id, segment_start, segment_end, active_count
    FROM closure_sweep
    WHERE active_count > 0 AND segment_start < segment_end
),
hospital_coverage_extent AS (
    SELECT hospital_id, MIN(clipped_start) AS observed_start, MAX(clipped_end) AS observed_end
    FROM valid_coverage
    GROUP BY hospital_id
),
hospital_windows AS (
    SELECT hospital_id,
           COALESCE({$fromUtc}, observed_start) AS window_start,
           COALESCE({$toUtc}, observed_end) AS window_end
    FROM hospital_coverage_extent
)
SQL;

        return [$sql, $params, $types];
    }
}
