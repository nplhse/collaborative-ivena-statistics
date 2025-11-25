<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Compute\Sql\ScopeFilterBuilder;
use App\Statistics\Infrastructure\Repository\AggScopeRepository;
use Doctrine\DBAL\Connection;

final class TransportTimeDimMatrixQuery extends AbstractTransportTimeBase
{
    public function __construct(
        private readonly Connection $db,
        protected ScopeFilterBuilder $filter,
        private readonly AggScopeRepository $aggScopeRepository,
    ) {
        parent::__construct($filter);
    }

    /**
     * Low-level query: returns raw rows from agg_allocations_transport_time_dim
     * for a given scope + dimType.
     *
     * Each row shape:
     *  - dim_id      (int)
     *  - bucket_key  (string)
     *  - n_total     (int)
     *
     * @return list<array{dim_id:int,bucket_key:string,n_total:int}>
     */
    public function fetchRaw(Scope $scope, string $dimType): array
    {
        $aggScopeId = $this->aggScopeRepository->findIdForScope($scope);
        if (null === $aggScopeId) {
            return [];
        }

        $sql = <<<SQL
SELECT
    dim_id::int    AS dim_id,
    bucket_key     AS bucket_key,
    n_total::int   AS n_total
FROM agg_allocations_transport_time_dim
WHERE agg_scope_id = :sid
  AND dim_type = :dim
ORDER BY dim_id ASC, bucket_key ASC;
SQL;

        /** @var list<array{dim_id:int,bucket_key:string,n_total:int}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, [
            'sid' => $aggScopeId,
            'dim' => $dimType,
        ]);

        return $rows;
    }
}
