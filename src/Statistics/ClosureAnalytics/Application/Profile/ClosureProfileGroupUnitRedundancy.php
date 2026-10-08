<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalSql;
use Doctrine\DBAL\Connection;

/**
 * Detects MD5 group profiles that are strictly equivalent to a local closure_unit profile in the same period.
 *
 * @see docs/04-features/statistics/closure-analytics.md
 */
final readonly class ClosureProfileGroupUnitRedundancy
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, string> keys "hospitalId:profileKey" => closure_unit
     */
    public function redundantGroupKeys(ClosureAnalyticsCriteria $criteria): array
    {
        if ([] === ($criteria->scope->hospitalIds ?? [])) {
            return [];
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        /** @var list<array{hospital_id: int|string, profile_key: string, closure_unit: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
period_events AS (
    SELECT DISTINCT event_key::bigint AS event_id, hospital_id
    FROM valid_closures
),
overview_token AS (
    SELECT pe.event_id,
           pe.hospital_id,
           ai.speciality_id::text || ':' || ai.department_id::text || ':' || cl.care_level || ':' || ai.reason AS token
    FROM period_events pe
    INNER JOIN closure_analysis_interval ai ON ai.event_id = pe.event_id AND ai.hospital_id = pe.hospital_id
    INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
),
overview_signature AS (
    SELECT event_id,
           hospital_id,
           md5(string_agg(DISTINCT token, ',' ORDER BY token)) AS profile_key
    FROM overview_token
    GROUP BY event_id, hospital_id
),
event_closure_units AS (
    SELECT event_key::bigint AS event_id,
           hospital_id,
           COUNT(DISTINCT NULLIF(BTRIM(closure_unit), '')) AS unit_variants,
           MIN(NULLIF(BTRIM(closure_unit), '')) AS sole_unit
    FROM valid_closures
    GROUP BY event_key, hospital_id
),
joined AS (
    SELECT os.event_id,
           os.hospital_id,
           os.profile_key,
           ecu.unit_variants,
           ecu.sole_unit
    FROM overview_signature os
    INNER JOIN event_closure_units ecu ON ecu.event_id = os.event_id AND ecu.hospital_id = os.hospital_id
),
key_agg AS (
    SELECT hospital_id,
           profile_key,
           COUNT(*) AS total_events,
           COUNT(*) FILTER (WHERE unit_variants = 1 AND sole_unit IS NOT NULL) AS homog_events,
           COUNT(DISTINCT sole_unit) FILTER (WHERE unit_variants = 1 AND sole_unit IS NOT NULL) AS units_among_homog
    FROM joined
    GROUP BY hospital_id, profile_key
),
unit_agg AS (
    SELECT hospital_id,
           sole_unit AS closure_unit,
           COUNT(*) AS homog_events,
           COUNT(DISTINCT profile_key) AS distinct_keys
    FROM joined
    WHERE unit_variants = 1 AND sole_unit IS NOT NULL
    GROUP BY hospital_id, sole_unit
),
key_unit AS (
    SELECT j.hospital_id,
           j.profile_key,
           MIN(j.sole_unit) AS closure_unit
    FROM joined j
    WHERE j.unit_variants = 1 AND j.sole_unit IS NOT NULL
    GROUP BY j.hospital_id, j.profile_key
    HAVING COUNT(*) = COUNT(*) FILTER (WHERE j.unit_variants = 1 AND j.sole_unit IS NOT NULL)
       AND COUNT(DISTINCT j.sole_unit) = 1
)
SELECT ka.hospital_id,
       ka.profile_key,
       ku.closure_unit
FROM key_agg ka
INNER JOIN key_unit ku ON ku.hospital_id = ka.hospital_id AND ku.profile_key = ka.profile_key
INNER JOIN unit_agg ua ON ua.hospital_id = ka.hospital_id AND ua.closure_unit = ku.closure_unit
WHERE ka.total_events = ka.homog_events
  AND ka.units_among_homog = 1
  AND ka.homog_events = ua.homog_events
  AND ua.distinct_keys = 1
SQL, $params, $types);

        $map = [];
        foreach ($rows as $row) {
            $hospitalId = (int) $row['hospital_id'];
            $profileKey = $row['profile_key'];
            $unit = $row['closure_unit'];
            $map[$this->mapKey($hospitalId, $profileKey)] = $unit;
        }

        return $map;
    }

    public function redundantUnitForGroup(
        ClosureAnalyticsCriteria $criteria,
        int $hospitalId,
        string $profileKey,
    ): ?string {
        $key = $this->mapKey($hospitalId, $profileKey);

        return $this->redundantGroupKeys($criteria)[$key] ?? null;
    }

    private function mapKey(int $hospitalId, string $profileKey): string
    {
        return $hospitalId.':'.$profileKey;
    }
}
