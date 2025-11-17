<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use App\Query\TransportTimeDimMatrixQuery;

final class TransportTimeDimMatrixReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly TransportTimeDimMatrixQuery $query,
    ) {
    }

    /**
     * @return list<array{
     *   dimId: int,
     *   buckets: array<string,int>,
     *   total: int
     * }>
     */
    public function readMatrix(Scope $scope, string $dimType): array
    {
        $rows = $this->query->fetchRaw($scope, $dimType);

        if ([] === $rows) {
            return [];
        }

        /** @var array<int, array{dimId:int,buckets:array<string,int>,total:int}> $byDim */
        $byDim = [];

        foreach ($rows as $r) {
            $dimId = $r['dim_id'];
            $bucketKey = $r['bucket_key'];
            $nTotal = $r['n_total'];

            if (!isset($byDim[$dimId])) {
                $byDim[$dimId] = [
                    'dimId' => $dimId,
                    'buckets' => [],
                    'total' => 0,
                ];
            }

            $byDim[$dimId]['buckets'][$bucketKey] = $nTotal;
            $byDim[$dimId]['total'] += $nTotal;
        }

        $out = array_values($byDim);

        usort(
            $out,
            static fn (array $a, array $b): int => $b['total'] <=> $a['total']
        );

        return $out;
    }
}
