<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopePeriodSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 100])]
final class OverviewCountCalculator implements CalculatorInterface
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

        $scopeWhere = $slice['sql'];
        $scopeParams = $slice['params'];
        $periodExpr = $period['sql'];
        $periodParams = $period['params'];

        $params = array_merge($scopeParams, $periodParams);

        $sql = <<<SQL
WITH relevant AS (
    SELECT *
    FROM allocation
    WHERE {$scopeWhere}
      AND {$periodExpr}
)
SELECT
    COUNT(*) AS total,

    COUNT(*) FILTER (WHERE gender = 'M') AS gender_m,
    COUNT(*) FILTER (WHERE gender = 'F') AS gender_w,
    COUNT(*) FILTER (WHERE gender = 'X') AS gender_d,
    0 AS gender_u,

    COUNT(*) FILTER (WHERE urgency = 1) AS urg_1,
    COUNT(*) FILTER (WHERE urgency = 2) AS urg_2,
    COUNT(*) FILTER (WHERE urgency = 3) AS urg_3,

    COUNT(*) FILTER (WHERE requires_cathlab)  AS cathlab_required,
    COUNT(*) FILTER (WHERE requires_resus)    AS resus_required,

    COUNT(*) FILTER (WHERE is_cpr)            AS is_cpr,
    COUNT(*) FILTER (WHERE is_ventilated)     AS is_ventilated,
    COUNT(*) FILTER (WHERE is_shock)          AS is_shock,
    COUNT(*) FILTER (WHERE is_pregnant)       AS is_pregnant,
    COUNT(*) FILTER (WHERE is_with_physician) AS with_physician,

    COUNT(*) FILTER (WHERE infection_id IS NOT NULL) AS infectious
FROM relevant;
SQL;

        $row = $this->db->fetchAssociative($sql, $params);

        if (false === $row) {
            $row = [
                'total' => 0,
                'gender_m' => 0,
                'gender_w' => 0,
                'gender_d' => 0,
                'gender_u' => 0,
                'urg_1' => 0,
                'urg_2' => 0,
                'urg_3' => 0,
                'cathlab_required' => 0,
                'resus_required' => 0,
                'is_cpr' => 0,
                'is_ventilated' => 0,
                'is_shock' => 0,
                'is_pregnant' => 0,
                'with_physician' => 0,
                'infectious' => 0,
            ];
        }

        $upsert = <<<SQL
INSERT INTO agg_allocations_counts (
    scope_type, scope_id, period_gran, period_key,
    total,
    gender_m, gender_w, gender_d, gender_u,
    urg_1, urg_2, urg_3,
    cathlab_required, resus_required,
    is_cpr, is_ventilated, is_shock, is_pregnant, with_physician,
    infectious
)
VALUES (
    :scope_type, :scope_id, :period_gran, :period_key,
    :total,
    :gender_m, :gender_w, :gender_d, :gender_u,
    :urg_1, :urg_2, :urg_3,
    :cathlab_required, :resus_required,
    :is_cpr, :is_ventilated, :is_shock, :is_pregnant, :with_physician,
    :infectious
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    total            = EXCLUDED.total,
    gender_m         = EXCLUDED.gender_m,
    gender_w         = EXCLUDED.gender_w,
    gender_d         = EXCLUDED.gender_d,
    gender_u         = EXCLUDED.gender_u,
    urg_1            = EXCLUDED.urg_1,
    urg_2            = EXCLUDED.urg_2,
    urg_3            = EXCLUDED.urg_3,
    cathlab_required = EXCLUDED.cathlab_required,
    resus_required   = EXCLUDED.resus_required,
    is_cpr           = EXCLUDED.is_cpr,
    is_ventilated    = EXCLUDED.is_ventilated,
    is_shock         = EXCLUDED.is_shock,
    is_pregnant      = EXCLUDED.is_pregnant,
    with_physician   = EXCLUDED.with_physician,
    infectious       = EXCLUDED.infectious,
    computed_at      = now();
SQL;

        $upParams = $row + [
            'scope_type' => $scope->scopeType,
            'scope_id' => $scope->scopeId,
            'period_gran' => $scope->granularity,
            'period_key' => $scope->periodKey,
        ];

        $this->db->executeStatement($upsert, $upParams);
    }
}
