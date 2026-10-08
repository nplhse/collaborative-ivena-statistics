<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

final class ClosureProfileSql
{
    /**
     * Full member set of one hospital, ignoring time bounds.
     * Requires :profile_hospital_id. facility_kind and closure_unit stay out of the token.
     */
    public static function signatureCtes(): string
    {
        return <<<'SQL'
profile_member_token AS (
    SELECT ai.event_id,
           ai.hospital_id,
           ai.speciality_id::text || ':' || ai.department_id::text || ':' || cl.care_level || ':' || ai.reason AS token
    FROM closure_analysis_interval ai
    INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
    WHERE ai.hospital_id = :profile_hospital_id
),
profile_signature AS (
    SELECT event_id,
           hospital_id,
           md5(string_agg(DISTINCT token, ',' ORDER BY token)) AS profile_key
    FROM profile_member_token
    GROUP BY event_id, hospital_id
)
SQL;
    }
}
