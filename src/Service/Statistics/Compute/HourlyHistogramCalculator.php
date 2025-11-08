<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopePeriodSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 50])]
final class HourlyHistogramCalculator implements CalculatorInterface
{
    use ScopePeriodSql;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    #[\Override]
    public function supports(Scope $scope): bool
    {
        return true;
    }

    #[\Override]
    public function calculate(Scope $scope): void
    {
        $slice = $this->buildScopeWhere($scope);
        $period = $this->buildPeriodExpr($scope);

        $params = array_merge($slice['params'], $period['params']);
        $scopeWhere = $slice['sql'];
        $periodExpr = $period['sql'];

        $sql = <<<SQL
WITH relevant AS (
    SELECT
        EXTRACT(HOUR FROM (arrival_at AT TIME ZONE 'Europe/Berlin'))::int AS hour,
        gender,
        urgency,
        requires_cathlab,
        requires_resus,
        is_cpr,
        is_ventilated,
        is_shock,
        is_pregnant,
        is_with_physician,
        (infection_id IS NOT NULL) AS infectious
    FROM allocation
    WHERE {$scopeWhere}
      AND {$periodExpr}
),
per_hour AS (
    SELECT
        hour,
        COUNT(*)::int                                                      AS total,

        COUNT(*) FILTER (WHERE gender = 'M')::int                          AS gender_m,
        COUNT(*) FILTER (WHERE gender = 'F')::int                          AS gender_w,
        COUNT(*) FILTER (WHERE gender = 'X')::int                          AS gender_d,
        COUNT(*) FILTER (WHERE gender IS NULL)::int                        AS gender_u,

        COUNT(*) FILTER (WHERE urgency = 1)::int                           AS urg_1,
        COUNT(*) FILTER (WHERE urgency = 2)::int                           AS urg_2,
        COUNT(*) FILTER (WHERE urgency = 3)::int                           AS urg_3,

        COUNT(*) FILTER (WHERE requires_cathlab)::int                      AS cathlab_required,
        COUNT(*) FILTER (WHERE requires_resus)::int                        AS resus_required,

        COUNT(*) FILTER (WHERE is_cpr)::int                                AS is_cpr,
        COUNT(*) FILTER (WHERE is_ventilated)::int                         AS is_ventilated,
        COUNT(*) FILTER (WHERE is_shock)::int                              AS is_shock,
        COUNT(*) FILTER (WHERE is_pregnant)::int                           AS is_pregnant,
        COUNT(*) FILTER (WHERE is_with_physician)::int                     AS with_physician,
        COUNT(*) FILTER (WHERE infectious)::int                            AS infectious
    FROM relevant
    GROUP BY hour
),
hours AS (
    SELECT gs AS hour
    FROM generate_series(0, 23) AS gs
),
joined AS (
    SELECT
        h.hour,
        COALESCE(p.total, 0)                AS total,
        COALESCE(p.gender_m, 0)             AS gender_m,
        COALESCE(p.gender_w, 0)             AS gender_w,
        COALESCE(p.gender_d, 0)             AS gender_d,
        COALESCE(p.gender_u, 0)             AS gender_u,
        COALESCE(p.urg_1, 0)                AS urg_1,
        COALESCE(p.urg_2, 0)                AS urg_2,
        COALESCE(p.urg_3, 0)                AS urg_3,
        COALESCE(p.cathlab_required, 0)     AS cathlab_required,
        COALESCE(p.resus_required, 0)       AS resus_required,
        COALESCE(p.is_cpr, 0)               AS is_cpr,
        COALESCE(p.is_ventilated, 0)        AS is_ventilated,
        COALESCE(p.is_shock, 0)             AS is_shock,
        COALESCE(p.is_pregnant, 0)          AS is_pregnant,
        COALESCE(p.with_physician, 0)       AS with_physician,
        COALESCE(p.infectious, 0)           AS infectious
    FROM hours h
    LEFT JOIN per_hour p ON p.hour = h.hour
    ORDER BY h.hour
)
SELECT jsonb_build_object(
    'total',            ARRAY(SELECT total            FROM joined ORDER BY hour),
    'gender_m',         ARRAY(SELECT gender_m         FROM joined ORDER BY hour),
    'gender_w',         ARRAY(SELECT gender_w         FROM joined ORDER BY hour),
    'gender_d',         ARRAY(SELECT gender_d         FROM joined ORDER BY hour),
    'gender_u',         ARRAY(SELECT gender_u         FROM joined ORDER BY hour),
    'urg_1',            ARRAY(SELECT urg_1            FROM joined ORDER BY hour),
    'urg_2',            ARRAY(SELECT urg_2            FROM joined ORDER BY hour),
    'urg_3',            ARRAY(SELECT urg_3            FROM joined ORDER BY hour),
    'cathlab_required', ARRAY(SELECT cathlab_required FROM joined ORDER BY hour),
    'resus_required',   ARRAY(SELECT resus_required   FROM joined ORDER BY hour),
    'is_cpr',           ARRAY(SELECT is_cpr           FROM joined ORDER BY hour),
    'is_ventilated',    ARRAY(SELECT is_ventilated    FROM joined ORDER BY hour),
    'is_shock',         ARRAY(SELECT is_shock         FROM joined ORDER BY hour),
    'is_pregnant',      ARRAY(SELECT is_pregnant      FROM joined ORDER BY hour),
    'with_physician',   ARRAY(SELECT with_physician   FROM joined ORDER BY hour),
    'infectious',       ARRAY(SELECT infectious       FROM joined ORDER BY hour)
) AS hours_count;
SQL;

        $row = $this->db->fetchAssociative($sql, $params);

        /* @var string $hoursJson */
        if (false !== $row && array_key_exists('hours_count', $row) && null !== $row['hours_count']) {
            $hoursJson = is_string($row['hours_count'])
                ? $row['hours_count']
                : json_encode($row['hours_count'], JSON_THROW_ON_ERROR);
        } else {
            $zeros24 = array_fill(0, 24, 0);
            $hoursJson = json_encode([
                'total' => $zeros24,
                'gender_m' => $zeros24,
                'gender_w' => $zeros24,
                'gender_d' => $zeros24,
                'gender_u' => $zeros24,
                'urg_1' => $zeros24,
                'urg_2' => $zeros24,
                'urg_3' => $zeros24,
                'cathlab_required' => $zeros24,
                'resus_required' => $zeros24,
                'is_cpr' => $zeros24,
                'is_ventilated' => $zeros24,
                'is_shock' => $zeros24,
                'is_pregnant' => $zeros24,
                'with_physician' => $zeros24,
                'infectious' => $zeros24,
            ], JSON_THROW_ON_ERROR);
        }

        $this->db->executeStatement(
            <<<SQL
INSERT INTO agg_allocations_hourly (scope_type, scope_id, period_gran, period_key, hours_count)
VALUES (:scope_type, :scope_id, :period_gran, :period_key, :hours_count)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    hours_count = EXCLUDED.hours_count,
    computed_at = now()
SQL,
            [
                'scope_type' => $scope->scopeType,
                'scope_id' => $scope->scopeId,
                'period_gran' => $scope->granularity,
                'period_key' => $scope->periodKey,
                'hours_count' => $hoursJson,
            ]
        );
    }
}
