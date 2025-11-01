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

        $scopeWhere = $slice['sql'];
        $scopeParams = $slice['params'];
        $periodExpr = $period['sql'];
        $periodParams = $period['params'];

        $params = array_merge($scopeParams, $periodParams);

        $sql = <<<SQL
WITH relevant AS (
    SELECT EXTRACT(HOUR FROM (arrival_at AT TIME ZONE 'Europe/Berlin'))::int AS h
    FROM allocation
    WHERE {$scopeWhere}
      AND {$periodExpr}
),
hist AS (
    SELECT ARRAY(
        SELECT COALESCE(SUM((h = i)::int),0)
        FROM generate_series(0,23) AS i
    ) AS hours_count
    FROM relevant
)
SELECT hours_count FROM hist;
SQL;

        $row = $this->db->fetchAssociative($sql, $params);

        if (false !== $row) {
            $row = ['hours_count' => '{'.implode(',', array_fill(0, 24, 0)).'}'];
        } else {
            $row = ['hours_count' => 0];
        }

        $upsert = <<<SQL
INSERT INTO agg_allocations_hourly (
    scope_type, scope_id, period_gran, period_key, hours_count
)
VALUES (
    :scope_type, :scope_id, :period_gran, :period_key, :hours_count
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    hours_count = EXCLUDED.hours_count,
    computed_at = now();
SQL;

        $this->db->executeStatement($upsert, [
            'scope_type' => $scope->scopeType,
            'scope_id' => $scope->scopeId,
            'period_gran' => $scope->granularity,
            'period_key' => $scope->periodKey,
            'hours_count' => $row['hours_count'],
        ]);
    }
}
