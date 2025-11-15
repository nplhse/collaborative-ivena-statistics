<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use App\Repository\AggScopeRepository;
use Doctrine\DBAL\Connection;

final class TransportTimeDispatchAreaTopReader
{
    public function __construct(
        private readonly Connection $db,
        private readonly AggScopeRepository $aggScopeRepository,
    ) {
    }

    /**
     * @return list<array{
     *   dispatchAreaId:int,
     *   total:int,
     *   share:float,
     *   withPhysician:int,
     *   withPhysicianShare:float
     * }>
     */
    public function readTopByDispatchArea(Scope $scope, int $limit = 10): array
    {
        $aggScopeId = $this->aggScopeRepository->findIdForScope($scope);
        if (null === $aggScopeId) {
            return [];
        }

        // WICHTIG: dim_id statt dim_key benutzen
        $sql = <<<SQL
SELECT
  dim_id::int                                           AS dispatch_area_id,
  SUM(n_total)::int                                       AS total,
  SUM(n_with_physician)::int                              AS with_physician,
  SUM(SUM(n_total)) OVER ()::int                          AS grand_total
FROM agg_allocations_transport_time_dim
WHERE agg_scope_id = :sid
  AND dim_type = 'dispatch_area'
GROUP BY dim_id
ORDER BY total DESC
LIMIT :lim;
SQL;

        // LIMIT als int binden
        $rows = $this->db->fetchAllAssociative(
            $sql,
            [
                'sid' => $aggScopeId,
                'lim' => $limit,
            ]
        );

        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $withPhysician = (int) ($r['with_physician'] ?? 0);
            $grandTotal = (int) ($r['grand_total'] ?? 0);

            $share = $grandTotal > 0 ? $total / $grandTotal : 0.0;
            $withPhysicianShare = $total > 0 ? $withPhysician / $total : 0.0;

            $out[] = [
                'dispatchAreaId' => (int) $r['dispatch_area_id'],
                'total' => $total,
                'share' => $share,
                'withPhysician' => $withPhysician,
                'withPhysicianShare' => $withPhysicianShare,
            ];
        }

        return $out;
    }
}
