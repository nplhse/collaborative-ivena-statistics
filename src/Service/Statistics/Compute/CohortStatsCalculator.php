<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 10])]
final class CohortStatsCalculator implements CalculatorInterface
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

        $metrics = [
            'gender_m', 'gender_w', 'gender_d', 'gender_u',
            'urg_1', 'urg_2', 'urg_3',
            'cathlab_required', 'resus_required',
            'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant', 'with_physician', 'infectious',
        ];

        // Dynamisches JSONB-Objekt mit mean/sd/var für jede Kennzahl
        $ratePieces = [];
        foreach ($metrics as $m) {
            $ratePieces[] = <<<SQL
'{$m}', jsonb_build_object(
  'mean', AVG(c.{$m}::numeric / NULLIF(c.total,0)) FILTER (WHERE c.total > 0),
  'sd',   STDDEV_SAMP(c.{$m}::numeric / NULLIF(c.total,0)) FILTER (WHERE c.total > 0),
  'var',  VAR_SAMP(c.{$m}::numeric / NULLIF(c.total,0)) FILTER (WHERE c.total > 0)
)
SQL;
        }

        $ratesJson = 'jsonb_build_object('.\implode(',', $ratePieces).')';

        $sql = <<<SQL
WITH base AS (
  SELECT c.*
  FROM agg_allocations_counts c
  JOIN hospital h ON h.id::text = c.scope_id
  WHERE c.scope_type = 'hospital'
    AND c.period_gran = :gran
    AND c.period_key  = :key
    AND {$whereHospital}
)
INSERT INTO agg_allocations_cohort_stats (
  scope_type, scope_id, period_gran, period_key,
  n, mean_total, rates
)
SELECT
  :scope_type, :scope_id, :gran, :key,
  COUNT(*) FILTER (WHERE total > 0) AS n,
  COALESCE(AVG(total::numeric), 0) AS mean_total,
  {$ratesJson} AS rates
FROM base c
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
  n = EXCLUDED.n,
  mean_total = EXCLUDED.mean_total,
  rates = EXCLUDED.rates,
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
            'hospital_tier' => ['h.tier = :hv', ['hv' => $s->scopeId]],
            'hospital_size' => ['h.size = :hv', ['hv' => $s->scopeId]],
            'hospital_location' => ['h.location = :hv', ['hv' => $s->scopeId]],
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

                return ['h.tier = :t AND h.location = :l', ['t' => $tier, 'l' => $location]];
            })($s->scopeId),
            default => throw new \LogicException('Unsupported scopeType '.$s->scopeType),
        };
    }
}
