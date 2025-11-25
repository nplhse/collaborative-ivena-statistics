<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Compute;

use App\Statistics\Application\Contract\CalculatorInterface;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Compute\Sql\ScopeFilterBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 95])]
final class AgeCohortShareCalculator implements CalculatorInterface
{
    private const array ORDER = ['<18', '18-29', '30-39', '40-49', '50-59', '60-69', '70-79', '80-89', '90-99'];

    public function __construct(
        private readonly Connection $db,
        private readonly ScopeFilterBuilder $filter,
    ) {
    }

    #[\Override]
    public function supports(Scope $scope): bool
    {
        // Only compute for monthly-or-coarser granularities to keep volume manageable.
        // Adjust keys if your system uses different constants.
        $allowedGranularities = ['all', 'month', 'quarter', 'year'];
        if (!\in_array($scope->granularity, $allowedGranularities, true)) {
            return false;
        }

        // Allow these scope types
        return \in_array(
            $scope->scopeType,
            ['public', 'all', 'hospital', 'dispatch_area', 'state', 'hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'],
            true
        );
    }

    #[\Override]
    public function calculate(Scope $scope): void
    {
        [$fromSql, $whereSql, $params] = $this->filter->buildBaseFilter($scope);

        $sql = <<<SQL
WITH cohortized AS (
  SELECT
    a.*,
    CASE
      WHEN a.age < 18 THEN '<18'
      WHEN a.age BETWEEN 18 AND 29 THEN '18-29'
      WHEN a.age BETWEEN 30 AND 39 THEN '30-39'
      WHEN a.age BETWEEN 40 AND 49 THEN '40-49'
      WHEN a.age BETWEEN 50 AND 59 THEN '50-59'
      WHEN a.age BETWEEN 60 AND 69 THEN '60-69'
      WHEN a.age BETWEEN 70 AND 79 THEN '70-79'
      WHEN a.age BETWEEN 80 AND 89 THEN '80-89'
      WHEN a.age BETWEEN 90 AND 99 THEN '90-99'
    END AS cohort
  FROM {$fromSql}
  WHERE {$whereSql}
    AND a.age IS NOT NULL
    AND a.age BETWEEN 1 AND 99
),
cohort_aspect_counts AS (
  SELECT
    cohort,
    COUNT(*)::int                                         AS total,
    COUNT(*) FILTER (WHERE gender = 'M')::int             AS gender_m,
    COUNT(*) FILTER (WHERE gender = 'F')::int             AS gender_w,
    COUNT(*) FILTER (WHERE gender = 'X')::int             AS gender_d,
    COUNT(*) FILTER (WHERE urgency = 1)::int              AS urg_1,
    COUNT(*) FILTER (WHERE urgency = 2)::int              AS urg_2,
    COUNT(*) FILTER (WHERE urgency = 3)::int              AS urg_3,
    COUNT(*) FILTER (WHERE requires_cathlab)::int         AS cathlab_required,
    COUNT(*) FILTER (WHERE requires_resus)::int           AS resus_required,
    COUNT(*) FILTER (WHERE is_cpr)::int                   AS is_cpr,
    COUNT(*) FILTER (WHERE is_ventilated)::int            AS is_ventilated,
    COUNT(*) FILTER (WHERE is_shock)::int                 AS is_shock,
    COUNT(*) FILTER (WHERE is_pregnant)::int              AS is_pregnant,
    COUNT(*) FILTER (WHERE is_with_physician)::int        AS with_physician,
    COUNT(*) FILTER (WHERE infection_id IS NOT NULL)::int AS infectious
  FROM cohortized
  GROUP BY cohort
),
totals AS (
  SELECT
    SUM(total)              AS total,
    SUM(gender_m)           AS gender_m,
    SUM(gender_w)           AS gender_w,
    SUM(gender_d)           AS gender_d,
    SUM(urg_1)              AS urg_1,
    SUM(urg_2)              AS urg_2,
    SUM(urg_3)              AS urg_3,
    SUM(cathlab_required)   AS cathlab_required,
    SUM(resus_required)     AS resus_required,
    SUM(is_cpr)             AS is_cpr,
    SUM(is_ventilated)      AS is_ventilated,
    SUM(is_shock)           AS is_shock,
    SUM(is_pregnant)        AS is_pregnant,
    SUM(with_physician)     AS with_physician,
    SUM(infectious)         AS infectious
  FROM cohort_aspect_counts
),
overall_stats AS (
  SELECT
    AVG(age)::float         AS mean,
    VAR_SAMP(age)::float    AS variance,
    STDDEV_SAMP(age)::float AS stddev
  FROM cohortized
)
SELECT 'rows' AS t, to_jsonb(cac.*) AS payload FROM cohort_aspect_counts cac
UNION ALL
SELECT 'totals' AS t, to_jsonb(tot.*) AS payload FROM totals tot
UNION ALL
SELECT 'overall' AS t, to_jsonb(os.*) AS payload FROM overall_stats os
ORDER BY 1;
SQL;

        $res = $this->db->fetchAllAssociative($sql, $params);

        // Split result sets
        $byCohort = [];
        $totals = [];
        $overall = ['mean' => null, 'variance' => null, 'stddev' => null];

        foreach ($res as $r) {
            $pl = json_decode((string) $r['payload'], true, 512, JSON_THROW_ON_ERROR);
            switch ($r['t']) {
                case 'rows':
                    if (isset($pl['cohort']) && \is_string($pl['cohort'])) {
                        $byCohort[$pl['cohort']] = $pl;
                    }
                    break;
                case 'totals':
                    $totals = $pl;
                    break;
                case 'overall':
                    $overall = $pl;
                    break;
            }
        }

        // Build `{ key, n, share }` arrays per aspect in a fixed order
        $build = function (string $aspect) use ($byCohort, $totals): array {
            $den = (int) ($totals[$aspect] ?? 0);
            $out = [];
            foreach (self::ORDER as $key) {
                $row = $byCohort[$key] ?? null;
                $n = $row ? (int) $row[$aspect] : 0;
                $share = $den > 0 ? ($n / $den) : 0.0;
                $out[] = ['key' => $key, 'n' => $n, 'share' => $share];
            }

            return $out;
        };

        $payload = [
            'total' => $build('total'),
            'gender_m' => $build('gender_m'),
            'gender_w' => $build('gender_w'),
            'gender_d' => $build('gender_d'),
            'urg_1' => $build('urg_1'),
            'urg_2' => $build('urg_2'),
            'urg_3' => $build('urg_3'),
            'cathlab_required' => $build('cathlab_required'),
            'resus_required' => $build('resus_required'),
            'is_cpr' => $build('is_cpr'),
            'is_ventilated' => $build('is_ventilated'),
            'is_shock' => $build('is_shock'),
            'is_pregnant' => $build('is_pregnant'),
            'with_physician' => $build('with_physician'),
            'infectious' => $build('infectious'),
        ];

        // Overview stats: mean always; variance/stddev only for hospital cohorts
        $isAgeCohort = $this->isAgeCohortScope($scope);
        $overall_age_mean = $overall['mean'] ?? null;
        $overall_age_variance = $isAgeCohort ? ($overall['variance'] ?? null) : null;
        $overall_age_stddev = $isAgeCohort ? ($overall['stddev'] ?? null) : null;

        $upsert = <<<SQL
INSERT INTO agg_allocations_age_buckets (
  scope_type, scope_id, period_gran, period_key,
  total, gender_m, gender_w, gender_d, urg_1, urg_2, urg_3,
  cathlab_required, resus_required, is_cpr, is_ventilated, is_shock, is_pregnant, with_physician, infectious,
  overall_age_mean, overall_age_variance, overall_age_stddev
)
VALUES (
  :t, :i, :g, :k::date,
  :total::jsonb, :gender_m::jsonb, :gender_w::jsonb, :gender_d::jsonb, :urg_1::jsonb, :urg_2::jsonb, :urg_3::jsonb,
  :cathlab_required::jsonb, :resus_required::jsonb, :is_cpr::jsonb, :is_ventilated::jsonb, :is_shock::jsonb, :is_pregnant::jsonb, :with_physician::jsonb, :infectious::jsonb,
  :overall_age_mean, :overall_age_variance, :overall_age_stddev
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
  total             = EXCLUDED.total,
  gender_m          = EXCLUDED.gender_m,
  gender_w          = EXCLUDED.gender_w,
  gender_d          = EXCLUDED.gender_d,
  urg_1             = EXCLUDED.urg_1,
  urg_2             = EXCLUDED.urg_2,
  urg_3             = EXCLUDED.urg_3,
  cathlab_required  = EXCLUDED.cathlab_required,
  resus_required    = EXCLUDED.resus_required,
  is_cpr            = EXCLUDED.is_cpr,
  is_ventilated     = EXCLUDED.is_ventilated,
  is_shock          = EXCLUDED.is_shock,
  is_pregnant       = EXCLUDED.is_pregnant,
  with_physician    = EXCLUDED.with_physician,
  infectious        = EXCLUDED.infectious,
  overall_age_mean      = EXCLUDED.overall_age_mean,
  overall_age_variance  = EXCLUDED.overall_age_variance,
  overall_age_stddev    = EXCLUDED.overall_age_stddev,
  computed_at           = now();
SQL;

        $this->db->executeStatement($upsert, [
            't' => $scope->scopeType,
            'i' => $scope->scopeId,
            'g' => $scope->granularity,
            'k' => $scope->periodKey,
            'total' => json_encode($payload['total'], JSON_THROW_ON_ERROR),
            'gender_m' => json_encode($payload['gender_m'], JSON_THROW_ON_ERROR),
            'gender_w' => json_encode($payload['gender_w'], JSON_THROW_ON_ERROR),
            'gender_d' => json_encode($payload['gender_d'], JSON_THROW_ON_ERROR),
            'urg_1' => json_encode($payload['urg_1'], JSON_THROW_ON_ERROR),
            'urg_2' => json_encode($payload['urg_2'], JSON_THROW_ON_ERROR),
            'urg_3' => json_encode($payload['urg_3'], JSON_THROW_ON_ERROR),
            'cathlab_required' => json_encode($payload['cathlab_required'], JSON_THROW_ON_ERROR),
            'resus_required' => json_encode($payload['resus_required'], JSON_THROW_ON_ERROR),
            'is_cpr' => json_encode($payload['is_cpr'], JSON_THROW_ON_ERROR),
            'is_ventilated' => json_encode($payload['is_ventilated'], JSON_THROW_ON_ERROR),
            'is_shock' => json_encode($payload['is_shock'], JSON_THROW_ON_ERROR),
            'is_pregnant' => json_encode($payload['is_pregnant'], JSON_THROW_ON_ERROR),
            'with_physician' => json_encode($payload['with_physician'], JSON_THROW_ON_ERROR),
            'infectious' => json_encode($payload['infectious'], JSON_THROW_ON_ERROR),
            'overall_age_mean' => $overall_age_mean,
            'overall_age_variance' => $overall_age_variance, // NULL when not a cohort scope
            'overall_age_stddev' => $overall_age_stddev,   // NULL when not a cohort scope
        ]);
    }

    private function isAgeCohortScope(Scope $scope): bool
    {
        return 'hospital_cohort' === $scope->scopeType;
    }
}
