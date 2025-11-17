<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Scope;
use Doctrine\DBAL\Connection;

final class AggScopeRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function ensureIdForScope(Scope $scope): int
    {
        $sql = <<<SQL
INSERT INTO agg_scope (scope_type, scope_id, period_gran, period_key)
VALUES (:type, :id, :gran, :key::date)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET scope_type = EXCLUDED.scope_type
RETURNING id;
SQL;

        $id = $this->db->fetchOne($sql, [
            'type' => $scope->scopeType,
            'id' => $scope->scopeId,
            'gran' => $scope->granularity,
            'key' => $scope->periodKey,
        ]);

        if (null === $id) {
            throw new \RuntimeException('Failed to resolve agg_scope id for scope.');
        }

        return (int) $id;
    }

    public function findIdForScope(Scope $scope): ?int
    {
        $sql = <<<SQL
SELECT id
  FROM agg_scope
 WHERE scope_type  = :type
   AND scope_id    = :id
   AND period_gran = :gran
   AND period_key  = :key::date
LIMIT 1;
SQL;

        $id = $this->db->fetchOne($sql, [
            'type' => $scope->scopeType,
            'id' => $scope->scopeId,
            'gran' => $scope->granularity,
            'key' => $scope->periodKey,
        ]);

        return null === $id ? null : (int) $id;
    }
}
