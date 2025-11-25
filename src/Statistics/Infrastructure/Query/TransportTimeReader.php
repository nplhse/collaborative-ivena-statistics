<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Compute\Sql\ScopeFilterBuilder;
use Doctrine\DBAL\Connection;

final class TransportTimeReader extends AbstractTransportTimeBase
{
    public function __construct(
        private readonly Connection $db,
        ScopeFilterBuilder $filter,
    ) {
        parent::__construct($filter);
    }

    /**
     * @return array{payload: array<string,array<int,array{key:string,n:int,share:float}>>, mean:?float, variance:?float, stddev:?float}
     */
    public function fetchCoreBuckets(Scope $scope): array
    {
        [$fromSql, $whereSql, $params] = $this->buildFromWhere($scope);
        $cte = $this->timedCte($fromSql, $whereSql);
        $metrics = $this->coreMetricSelects();

        $sql = <<<SQL
{$cte},
bucket_aspect_counts AS (
  SELECT
    t_bucket,
    {$metrics}
  FROM timed
  GROUP BY t_bucket
),
totals AS (
  SELECT
    SUM(total)             AS total,
    SUM(with_physician)    AS with_physician,
    SUM(resus_req)         AS resus_req,
    SUM(cathlab_req)       AS cathlab_req,
    SUM(gender_m)          AS gender_m,
    SUM(gender_w)          AS gender_w,
    SUM(gender_d)          AS gender_d,
    SUM(urg_1)             AS urg_1,
    SUM(urg_2)             AS urg_2,
    SUM(urg_3)             AS urg_3,
    SUM(transport_ground)  AS transport_ground,
    SUM(transport_air)     AS transport_air
  FROM bucket_aspect_counts
),
overall_stats AS (
  SELECT
    AVG(transport_minutes)::float         AS mean,
    VAR_SAMP(transport_minutes)::float    AS variance,
    STDDEV_SAMP(transport_minutes)::float AS stddev
  FROM timed
)
SELECT 'rows' AS t, to_jsonb(bac.*) AS payload FROM bucket_aspect_counts bac
UNION ALL
SELECT 'totals' AS t, to_jsonb(tot.*) AS payload FROM totals tot
UNION ALL
SELECT 'overall' AS t, to_jsonb(os.*) AS payload FROM overall_stats os
ORDER BY 1;
SQL;

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params);

        if ([] === $rows) {
            return [
                'payload' => [
                    'total' => [],
                    'with_physician' => [],
                    'resus_req' => [],
                    'cathlab_req' => [],
                    'gender_m' => [],
                    'gender_w' => [],
                    'gender_d' => [],
                    'urg_1' => [],
                    'urg_2' => [],
                    'urg_3' => [],
                    'transport_ground' => [],
                    'transport_air' => [],
                ],
                'mean' => null,
                'variance' => null,
                'stddev' => null,
            ];
        }

        $byBucket = [];
        $totals = [];
        $overall = ['mean' => null, 'variance' => null, 'stddev' => null];

        foreach ($rows as $row) {
            $pl = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            switch ($row['t']) {
                case 'rows':
                    if (isset($pl['t_bucket']) && \is_string($pl['t_bucket'])) {
                        $byBucket[$pl['t_bucket']] = $pl;
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

        $buildAspect = function (string $aspect) use ($byBucket, $totals): array {
            $den = (int) ($totals[$aspect] ?? 0);
            $out = [];
            foreach (self::BUCKETS as $bucketKey) {
                $row = $byBucket[$bucketKey] ?? null;
                $n = $row ? (int) $row[$aspect] : 0;
                $share = $den > 0 ? ($n / $den) : 0.0;
                $out[] = ['key' => $bucketKey, 'n' => $n, 'share' => $share];
            }

            return $out;
        };

        $payload = [
            'total' => $buildAspect('total'),
            'with_physician' => $buildAspect('with_physician'),
            'resus_req' => $buildAspect('resus_req'),
            'cathlab_req' => $buildAspect('cathlab_req'),
            'gender_m' => $buildAspect('gender_m'),
            'gender_w' => $buildAspect('gender_w'),
            'gender_d' => $buildAspect('gender_d'),
            'urg_1' => $buildAspect('urg_1'),
            'urg_2' => $buildAspect('urg_2'),
            'urg_3' => $buildAspect('urg_3'),
            'transport_ground' => $buildAspect('transport_ground'),
            'transport_air' => $buildAspect('transport_air'),
        ];

        return [
            'payload' => $payload,
            'mean' => $overall['mean'] ?? null,
            'variance' => $overall['variance'] ?? null,
            'stddev' => $overall['stddev'] ?? null,
        ];
    }

    /**
     * Fetch per-dimension, per-bucket counts for a given dimension type.
     *
     * If $limit is null or <= 0, all dimensions are returned.
     * If $limit > 0, only the top-N dim_ids (by total n_total across buckets)
     * are included.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchDimensionBuckets(Scope $scope, string $dimType, ?int $limit = null): array
    {
        [$fromSql, $whereSql, $params] = $this->buildFromWhere($scope);
        $cte = $this->timedCte($fromSql, $whereSql);
        $col = $this->dimColumn($dimType);

        // No limit => original behaviour, no top-N filtering
        if (null === $limit || $limit <= 0) {
            $sql = <<<SQL
                    {$cte}
                    SELECT
                      t_bucket,
                      {$col} AS dim_id,
                      COUNT(*)::int                                      AS n_total,
                      COUNT(*) FILTER (WHERE is_with_physician)::int     AS n_with_physician,
                      COUNT(*) FILTER (WHERE requires_resus):: int       AS n_resus_req,
                      COUNT(*) FILTER (WHERE requires_cathlab)::int      AS n_cathlab_req,
                      COUNT(*) FILTER (WHERE urgency = 1)::int           AS n_urg_1,
                      COUNT(*) FILTER (WHERE urgency = 2)::int           AS n_urg_2,
                      COUNT(*) FILTER (WHERE urgency = 3)::int           AS n_urg_3,
                      COUNT(*) FILTER (WHERE transport_type = 'G')::int  AS n_transport_ground,
                      COUNT(*) FILTER (WHERE transport_type = 'A')::int  AS n_transport_air,
                      AVG(transport_minutes)::float                      AS mean_minutes,
                      VAR_SAMP(transport_minutes)::float                 AS variance_minutes,
                      STDDEV_SAMP(transport_minutes)::float              AS stddev_minutes
                    FROM timed
                    WHERE {$col} IS NOT NULL
                    GROUP BY t_bucket, {$col};
                    SQL;

            return $this->db->fetchAllAssociative($sql, $params);
        }

        // With limit => restrict to top-N dim_ids by n_total
        $sql = <<<SQL
        {$cte},
        dim_totals AS (
          SELECT
            {$col} AS dim_id,
            COUNT(*)::int AS n_total_dim
          FROM timed
          WHERE {$col} IS NOT NULL
          GROUP BY {$col}
        ),
        top_dims AS (
          SELECT dim_id
          FROM dim_totals
          ORDER BY n_total_dim DESC
          LIMIT :dim_limit
        )
        SELECT
          t_bucket,
          {$col} AS dim_id,
          COUNT(*)::int                                      AS n_total,
          COUNT(*) FILTER (WHERE is_with_physician)::int     AS n_with_physician,
          COUNT(*) FILTER (WHERE requires_resus):: int       AS n_resus_req,
          COUNT(*) FILTER (WHERE requires_cathlab)::int      AS n_cathlab_req,
          COUNT(*) FILTER (WHERE urgency = 1)::int           AS n_urg_1,
          COUNT(*) FILTER (WHERE urgency = 2)::int           AS n_urg_2,
          COUNT(*) FILTER (WHERE urgency = 3)::int           AS n_urg_3,
          COUNT(*) FILTER (WHERE transport_type = 'G')::int  AS n_transport_ground,
          COUNT(*) FILTER (WHERE transport_type = 'A')::int  AS n_transport_air,
          AVG(transport_minutes)::float                      AS mean_minutes,
          VAR_SAMP(transport_minutes)::float                 AS variance_minutes,
          STDDEV_SAMP(transport_minutes)::float              AS stddev_minutes
        FROM timed
        JOIN top_dims td ON td.dim_id = {$col}
        WHERE {$col} IS NOT NULL
        GROUP BY t_bucket, {$col};
        SQL;

        $params['dim_limit'] = $limit;

        return $this->db->fetchAllAssociative($sql, $params);
    }
}
