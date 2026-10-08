<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Builds analysis intervals and stable events from imported closure rows.
 * A volume rebuild does not call this class and therefore does not change event ids.
 */
final readonly class ClosureAnalysisRebuilder
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<int>|null $hospitalIds null rebuilds every hospital that has sources or events
     */
    public function rebuild(?array $hospitalIds = null): void
    {
        $ids = $hospitalIds ?? $this->hospitalIds();
        foreach ($ids as $hospitalId) {
            $this->connection->transactional(function () use ($hospitalId): void {
                $this->rebuildHospital($hospitalId);
            });
        }
    }

    /**
     * @return list<int>
     */
    public function hospitalIds(): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT hospital_id FROM (
    SELECT ci.hospital_id
    FROM closure_interval ci
    INNER JOIN import i ON i.id = ci.import_id
    WHERE i.status IN ('Completed', 'Partial')
    UNION
    SELECT hospital_id FROM closure_event
) hospitals
ORDER BY hospital_id ASC
SQL,
        );

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    private function rebuildHospital(int $hospitalId): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS closure_analysis_stage');
        $this->connection->executeStatement(
            <<<'SQL'
CREATE TEMP TABLE closure_analysis_stage (
    fingerprint TEXT PRIMARY KEY,
    speciality_id INT NOT NULL,
    department_id INT NOT NULL,
    starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    reason VARCHAR(64) NOT NULL,
    facility_kind VARCHAR(32) NOT NULL,
    closure_unit VARCHAR(255),
    source_group_id VARCHAR(32),
    care_levels TEXT[] NOT NULL,
    source_ids INT[] NOT NULL,
    grouping_key TEXT,
    event_type VARCHAR(32),
    grouping_rule VARCHAR(64)
) ON COMMIT DROP
SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_analysis_stage (
    fingerprint, speciality_id, department_id, starts_at, ends_at, reason, facility_kind,
    closure_unit, source_group_id, care_levels, source_ids
)
SELECT md5(concat_ws(E'\x1f',
           grouped.hospital_id::text,
           grouped.speciality_id::text,
           grouped.department_id::text,
           to_char(grouped.starts_at, 'YYYY-MM-DD HH24:MI:SS'),
           to_char(grouped.ends_at, 'YYYY-MM-DD HH24:MI:SS'),
           grouped.reason,
           grouped.facility_kind,
           grouped.unit_key,
           grouped.group_key
       )),
       grouped.speciality_id,
       grouped.department_id,
       grouped.starts_at,
       grouped.ends_at,
       grouped.reason,
       grouped.facility_kind,
       grouped.closure_unit,
       grouped.source_group_id,
       grouped.care_levels,
       grouped.source_ids
FROM (
    SELECT ci.hospital_id,
           ci.speciality_id,
           ci.department_id,
           ci.starts_at,
           ci.ends_at,
           ci.reason,
           ci.facility_kind,
           coalesce(ci.closure_unit, '') AS unit_key,
           coalesce(nullif(btrim(ci.source_group_id), ''), '') AS group_key,
           MIN(ci.closure_unit) AS closure_unit,
           MIN(NULLIF(BTRIM(ci.source_group_id), '')) AS source_group_id,
           array_agg(DISTINCT ci.care_level ORDER BY ci.care_level) AS care_levels,
           array_agg(ci.id ORDER BY ci.id) AS source_ids
    FROM closure_interval ci
    INNER JOIN import i ON i.id = ci.import_id
    WHERE ci.hospital_id = :hospital
      AND i.status IN ('Completed', 'Partial')
    GROUP BY ci.hospital_id, ci.speciality_id, ci.department_id, ci.starts_at, ci.ends_at,
             ci.reason, ci.facility_kind, coalesce(ci.closure_unit, ''),
             coalesce(nullif(btrim(ci.source_group_id), ''), '')
) AS grouped
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
UPDATE closure_analysis_stage
SET grouping_key = 'sg:' || CAST(:hospital AS text) || ':' || source_group_id,
    event_type = 'source_group',
    grouping_rule = 'ivena_group_id'
WHERE source_group_id IS NOT NULL
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
WITH counts AS (
    SELECT starts_at, ends_at, COUNT(*) AS coincident
    FROM closure_analysis_stage
    WHERE source_group_id IS NULL
    GROUP BY starts_at, ends_at
)
UPDATE closure_analysis_stage AS stage
SET grouping_key = CASE
        WHEN counts.coincident > 1 THEN 'cl:' || CAST(:hospital AS text) || ':' || to_char(stage.starts_at, 'YYYY-MM-DD HH24:MI:SS') || '|' || to_char(stage.ends_at, 'YYYY-MM-DD HH24:MI:SS')
        ELSE 'si:' || stage.fingerprint
    END,
    event_type = CASE WHEN counts.coincident > 1 THEN 'cluster' ELSE 'single' END,
    grouping_rule = CASE
        WHEN counts.coincident > 1 THEN 'exact_start_end_ungrouped_same_hospital'
        ELSE 'single_analysis_interval'
    END
FROM counts
WHERE stage.source_group_id IS NULL
  AND stage.starts_at = counts.starts_at
  AND stage.ends_at = counts.ends_at
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );

        $this->connection->executeStatement(
            'DELETE FROM closure_relation_candidate WHERE hospital_id = :hospital',
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
DELETE FROM closure_analysis_source
WHERE analysis_interval_id IN (SELECT id FROM closure_analysis_interval WHERE hospital_id = :hospital)
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
DELETE FROM closure_analysis_care_level
WHERE analysis_interval_id IN (SELECT id FROM closure_analysis_interval WHERE hospital_id = :hospital)
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            'DELETE FROM closure_analysis_interval WHERE hospital_id = :hospital',
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_event (hospital_id, event_type, grouping_key, source_group_id, grouping_rule, starts_at, ends_at)
SELECT :hospital, event_type, grouping_key, MIN(source_group_id), grouping_rule, MIN(starts_at), MAX(ends_at)
FROM closure_analysis_stage
GROUP BY event_type, grouping_key, grouping_rule
ON CONFLICT (grouping_key) DO UPDATE SET
    event_type = EXCLUDED.event_type,
    source_group_id = EXCLUDED.source_group_id,
    grouping_rule = EXCLUDED.grouping_rule,
    starts_at = EXCLUDED.starts_at,
    ends_at = EXCLUDED.ends_at,
    hospital_id = EXCLUDED.hospital_id
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
DELETE FROM closure_event AS event
WHERE event.hospital_id = :hospital
  AND NOT EXISTS (
      SELECT 1 FROM closure_analysis_stage AS stage WHERE stage.grouping_key = event.grouping_key
  )
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_analysis_interval (
    event_id, hospital_id, speciality_id, department_id, starts_at, ends_at,
    reason, facility_kind, closure_unit, source_group_id, fingerprint
)
SELECT event.id, :hospital, stage.speciality_id, stage.department_id, stage.starts_at, stage.ends_at,
       stage.reason, stage.facility_kind, stage.closure_unit, stage.source_group_id, stage.fingerprint
FROM closure_analysis_stage AS stage
INNER JOIN closure_event AS event ON event.grouping_key = stage.grouping_key
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_analysis_care_level (analysis_interval_id, care_level)
SELECT interval.id, level.care_level
FROM closure_analysis_interval AS interval
INNER JOIN closure_analysis_stage AS stage ON stage.fingerprint = interval.fingerprint
CROSS JOIN LATERAL unnest(stage.care_levels) AS level(care_level)
WHERE interval.hospital_id = :hospital
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_analysis_source (analysis_interval_id, closure_interval_id, import_id)
SELECT interval.id, source.closure_interval_id, closure.import_id
FROM closure_analysis_interval AS interval
INNER JOIN closure_analysis_stage AS stage ON stage.fingerprint = interval.fingerprint
CROSS JOIN LATERAL unnest(stage.source_ids) AS source(closure_interval_id)
INNER JOIN closure_interval AS closure ON closure.id = source.closure_interval_id
WHERE interval.hospital_id = :hospital
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_relation_candidate (hospital_id, analysis_interval_low_id, analysis_interval_high_id, kind)
SELECT left_interval.hospital_id, left_interval.id, right_interval.id,
       CASE
           WHEN left_interval.ends_at = right_interval.starts_at OR right_interval.ends_at = left_interval.starts_at THEN 'adjacent'
           ELSE 'overlap'
       END
FROM closure_analysis_interval AS left_interval
INNER JOIN closure_analysis_interval AS right_interval
    ON right_interval.hospital_id = left_interval.hospital_id
   AND right_interval.department_id = left_interval.department_id
   AND right_interval.id > left_interval.id
   AND right_interval.event_id <> left_interval.event_id
   AND (
        (left_interval.starts_at < right_interval.ends_at AND right_interval.starts_at < left_interval.ends_at)
        OR left_interval.ends_at = right_interval.starts_at
        OR right_interval.ends_at = left_interval.starts_at
   )
WHERE left_interval.hospital_id = :hospital
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            <<<'SQL'
UPDATE closure_relation_candidate AS candidate
SET kind = 'export_boundary'
WHERE candidate.hospital_id = :hospital
  AND EXISTS (
      SELECT 1
      FROM closure_analysis_source AS low_source
      INNER JOIN import AS low_import ON low_import.id = low_source.import_id
      INNER JOIN closure_analysis_interval AS low_interval ON low_interval.id = low_source.analysis_interval_id
      INNER JOIN closure_analysis_source AS high_source ON high_source.analysis_interval_id = candidate.analysis_interval_high_id
      INNER JOIN import AS high_import ON high_import.id = high_source.import_id
      INNER JOIN closure_analysis_interval AS high_interval ON high_interval.id = high_source.analysis_interval_id
      WHERE low_source.analysis_interval_id = candidate.analysis_interval_low_id
        AND low_import.export_ends_at IS NOT NULL
        AND high_import.export_starts_at IS NOT NULL
        AND low_interval.ends_at = low_import.export_ends_at
        AND high_interval.starts_at = high_import.export_starts_at
  )
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
    }
}
