<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 20])]
final class CohortSumsCalculator implements CalculatorInterface
{
    public function __construct(
        private Connection $db,
    ) {
    }

    #[\Override]
    public function supports(Scope $scope): bool
    {
        return \in_array(
            $scope->scopeType,
            ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'],
            true
        );
    }

    #[\Override]
    public function calculate(Scope $scope): void
    {
        [$whereHospital, $paramsHospital] = $this->buildHospitalFilter($scope);

        $sql = <<<SQL
INSERT INTO agg_allocations_cohort_sums (
  scope_type, scope_id, period_gran, period_key,
  total, gender_m, gender_w, gender_d, gender_u,
  urg_1, urg_2, urg_3,
  cathlab_required, resus_required,
  is_cpr, is_ventilated, is_shock, is_pregnant, with_physician, infectious
)
SELECT
  :scope_type, :scope_id, :gran, :key,
  COALESCE(SUM(c.total),0),
  COALESCE(SUM(c.gender_m),0),
  COALESCE(SUM(c.gender_w),0),
  COALESCE(SUM(c.gender_d),0),
  COALESCE(SUM(c.gender_u),0),
  COALESCE(SUM(c.urg_1),0),
  COALESCE(SUM(c.urg_2),0),
  COALESCE(SUM(c.urg_3),0),
  COALESCE(SUM(c.cathlab_required),0),
  COALESCE(SUM(c.resus_required),0),
  COALESCE(SUM(c.is_cpr),0),
  COALESCE(SUM(c.is_ventilated),0),
  COALESCE(SUM(c.is_shock),0),
  COALESCE(SUM(c.is_pregnant),0),
  COALESCE(SUM(c.with_physician),0),
  COALESCE(SUM(c.infectious),0)
FROM agg_allocations_counts c
JOIN hospital h ON h.id::text = c.scope_id
WHERE c.scope_type = 'hospital'
  AND c.period_gran = :gran
  AND c.period_key  = :key
  AND {$whereHospital}
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
  total = EXCLUDED.total,
  gender_m = EXCLUDED.gender_m,
  gender_w = EXCLUDED.gender_w,
  gender_d = EXCLUDED.gender_d,
  gender_u = EXCLUDED.gender_u,
  urg_1 = EXCLUDED.urg_1,
  urg_2 = EXCLUDED.urg_2,
  urg_3 = EXCLUDED.urg_3,
  cathlab_required = EXCLUDED.cathlab_required,
  resus_required = EXCLUDED.resus_required,
  is_cpr = EXCLUDED.is_cpr,
  is_ventilated = EXCLUDED.is_ventilated,
  is_shock = EXCLUDED.is_shock,
  is_pregnant = EXCLUDED.is_pregnant,
  with_physician = EXCLUDED.with_physician,
  infectious = EXCLUDED.infectious,
  computed_at = now();
SQL;

        $params = \array_merge([
            'scope_type' => $scope->scopeType,
            'scope_id' => $scope->scopeId,
            'gran' => $scope->granularity,
            'key' => $scope->periodKey,
        ], $paramsHospital);

        $this->db->executeStatement($sql, $params);
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function buildHospitalFilter(Scope $s): array
    {
        return match ($s->scopeType) {
            'hospital_tier' => ['LOWER(h.tier) = :hv', ['hv' => strtolower($s->scopeId)]],
            'hospital_size' => ['LOWER(h.size) = :hv', ['hv' => strtolower($s->scopeId)]],
            'hospital_location' => ['LOWER(h.location) = :hv', ['hv' => strtolower($s->scopeId)]],

            'hospital_cohort' => (static function (string $sid) {
                if (!preg_match(
                    '/^(?<tier>basic|extended|full)_(?<loc>urban|mixed|rural)$/i',
                    $sid,
                    $m
                )) {
                    throw new \LogicException("Invalid cohort id '{$sid}', expected '<tier>_<location>'");
                }

                $tier = strtolower($m['tier']);
                $location = strtolower($m['loc']);

                return ['LOWER(h.tier) = :t AND LOWER(h.location) = :l', ['t' => $tier, 'l' => $location]];
            })($s->scopeId),

            default => throw new \LogicException('Unsupported scopeType '.$s->scopeType),
        };
    }
}
