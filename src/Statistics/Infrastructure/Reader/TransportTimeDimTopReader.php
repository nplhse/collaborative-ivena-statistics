<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Reader;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Repository\AggScopeRepository;
use Doctrine\DBAL\Connection;

final class TransportTimeDimTopReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly Connection $db,
        private readonly AggScopeRepository $aggScopeRepository,
    ) {
    }

    /**
     * Generic top list for a given dimension type.
     *
     * @return list<array{
     *   dimId:int,
     *   total:int,
     *   share:float,
     *   withPhysician:int,
     *   withPhysicianShare:float
     * }>
     */
    public function readTop(Scope $scope, string $dimType, int $limit = 25, ?string $bucket = null): array
    {
        $aggScopeId = $this->aggScopeRepository->findIdForScope($scope);
        if (null === $aggScopeId) {
            return [];
        }

        // Build dynamic WHERE clause depending on bucket
        $where = <<<SQL
        WHERE agg_scope_id = :sid
          AND dim_type = :dim
        SQL;

        $params = [
            'sid' => $aggScopeId,
            'dim' => $dimType,
            'lim' => $limit,
        ];

        if (null !== $bucket && 'all' !== $bucket) {
            $where .= "\n  AND bucket_key = :bucket";
            $params['bucket'] = $bucket;
        }

        $sql = <<<SQL
                SELECT
                  dim_id::int                                           AS dim_id,
                  SUM(n_total)::int                                     AS total,
                  SUM(n_with_physician)::int                            AS with_physician,
                  SUM(SUM(n_total)) OVER ()::int                        AS grand_total
                FROM agg_allocations_transport_time_dim
                {$where}
                GROUP BY dim_id
                ORDER BY total DESC
                LIMIT :lim;
                SQL;

        $rows = $this->db->fetchAllAssociative($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $withPhysician = (int) ($r['with_physician'] ?? 0);
            $grandTotal = (int) ($r['grand_total'] ?? 0);

            $share = $grandTotal > 0 ? $total / $grandTotal : 0.0;
            $withPhysicianShare = $total > 0 ? $withPhysician / $total : 0.0;

            $out[] = [
                'dimId' => (int) $r['dim_id'],
                'total' => $total,
                'share' => $share,
                'withPhysician' => $withPhysician,
                'withPhysicianShare' => $withPhysicianShare,
            ];
        }

        return $out;
    }
}
