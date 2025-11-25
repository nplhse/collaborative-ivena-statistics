<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class TransportTimeWriter
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, array<int, array{key:string,n:int,share:float}>> $payload
     */
    public function saveCoreBuckets(int $aggScopeId, array $payload, ?float $mean, ?float $variance, ?float $stddev): void
    {
        $sql = <<<SQL
INSERT INTO agg_allocations_transport_time_buckets (
  agg_scope_id,
  total, with_physician, resus_req, cathlab_req,
  gender_m, gender_w, gender_d,
  urg_1, urg_2, urg_3,
  transport_ground, transport_air,
  overall_minutes_mean, overall_minutes_variance, overall_minutes_stddev
) VALUES (
  :sid,
  :total::jsonb, :with_physician::jsonb, :resus_req::jsonb, :cathlab_req::jsonb,
  :gender_m::jsonb, :gender_w::jsonb, :gender_d::jsonb,
  :urg_1::jsonb, :urg_2::jsonb, :urg_3::jsonb,
  :transport_ground::jsonb, :transport_air::jsonb,
  :mean, :variance, :stddev
)
ON CONFLICT (agg_scope_id)
DO UPDATE SET
  total                = EXCLUDED.total,
  with_physician       = EXCLUDED.with_physician,
  resus_req            = EXCLUDED.resus_req,
  cathlab_req          = EXCLUDED.cathlab_req,
  gender_m             = EXCLUDED.gender_m,
  gender_w             = EXCLUDED.gender_w,
  gender_d             = EXCLUDED.gender_d,
  urg_1                = EXCLUDED.urg_1,
  urg_2                = EXCLUDED.urg_2,
  urg_3                = EXCLUDED.urg_3,
  transport_ground     = EXCLUDED.transport_ground,
  transport_air        = EXCLUDED.transport_air,
  overall_minutes_mean = EXCLUDED.overall_minutes_mean,
  overall_minutes_variance = EXCLUDED.overall_minutes_variance,
  overall_minutes_stddev   = EXCLUDED.overall_minutes_stddev,
  computed_at              = now();
SQL;

        $this->db->executeStatement($sql, [
            'sid' => $aggScopeId,
            'total' => json_encode($payload['total'], JSON_THROW_ON_ERROR),
            'with_physician' => json_encode($payload['with_physician'], JSON_THROW_ON_ERROR),
            'resus_req' => json_encode($payload['resus_req'], JSON_THROW_ON_ERROR),
            'cathlab_req' => json_encode($payload['cathlab_req'], JSON_THROW_ON_ERROR),
            'gender_m' => json_encode($payload['gender_m'], JSON_THROW_ON_ERROR),
            'gender_w' => json_encode($payload['gender_w'], JSON_THROW_ON_ERROR),
            'gender_d' => json_encode($payload['gender_d'], JSON_THROW_ON_ERROR),
            'urg_1' => json_encode($payload['urg_1'], JSON_THROW_ON_ERROR),
            'urg_2' => json_encode($payload['urg_2'], JSON_THROW_ON_ERROR),
            'urg_3' => json_encode($payload['urg_3'], JSON_THROW_ON_ERROR),
            'transport_ground' => json_encode($payload['transport_ground'], JSON_THROW_ON_ERROR),
            'transport_air' => json_encode($payload['transport_air'], JSON_THROW_ON_ERROR),
            'mean' => $mean,
            'variance' => $variance,
            'stddev' => $stddev,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows from reader->fetchDimensionBuckets()
     */
    public function saveDimensionBuckets(int $aggScopeId, string $dimType, array $rows): void
    {
        $sql = <<<SQL
INSERT INTO agg_allocations_transport_time_dim (
  agg_scope_id, dim_type, dim_id, bucket_key,
  n_total, n_with_physician, n_resus_req, n_cathlab_req,
  n_urg_1, n_urg_2, n_urg_3,
  n_transport_ground, n_transport_air,
  mean_minutes, variance_minutes, stddev_minutes
) VALUES (
  :sid, :dt, :did, :bk,
  :n_total, :n_with_physician, :n_resus_req, :n_cathlab_req,
  :n_urg_1, :n_urg_2, :n_urg_3,
  :n_ground, :n_air,
  :mean, :variance, :stddev
)
ON CONFLICT (agg_scope_id, dim_type, dim_id, bucket_key)
DO UPDATE SET
  n_total           = EXCLUDED.n_total,
  n_with_physician  = EXCLUDED.n_with_physician,
  n_resus_req       = EXCLUDED.n_resus_req,
  n_cathlab_req     = EXCLUDED.n_cathlab_req,
  n_urg_1           = EXCLUDED.n_urg_1,
  n_urg_2           = EXCLUDED.n_urg_2,
  n_urg_3           = EXCLUDED.n_urg_3,
  n_transport_ground = EXCLUDED.n_transport_ground,
  n_transport_air    = EXCLUDED.n_transport_air,
  mean_minutes      = EXCLUDED.mean_minutes,
  variance_minutes  = EXCLUDED.variance_minutes,
  stddev_minutes    = EXCLUDED.stddev_minutes,
  computed_at       = now();
SQL;

        foreach ($rows as $row) {
            $this->db->executeStatement($sql, [
                'sid' => $aggScopeId,
                'dt' => $dimType,
                'did' => (int) $row['dim_id'],
                'bk' => (string) $row['t_bucket'],
                'n_total' => (int) $row['n_total'],
                'n_with_physician' => (int) $row['n_with_physician'],
                'n_resus_req' => (int) $row['n_resus_req'],
                'n_cathlab_req' => (int) $row['n_cathlab_req'],
                'n_urg_1' => (int) $row['n_urg_1'],
                'n_urg_2' => (int) $row['n_urg_2'],
                'n_urg_3' => (int) $row['n_urg_3'],
                'n_ground' => (int) $row['n_transport_ground'],
                'n_air' => (int) $row['n_transport_air'],
                'mean' => null !== $row['mean_minutes'] ? (float) $row['mean_minutes'] : null,
                'variance' => null !== $row['variance_minutes'] ? (float) $row['variance_minutes'] : null,
                'stddev' => null !== $row['stddev_minutes'] ? (float) $row['stddev_minutes'] : null,
            ]);
        }
    }
}
