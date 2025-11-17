<?php

declare(strict_types=1);

namespace App\Query;

use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopeFilterBuilder;

abstract class AbstractTransportTimeBase
{
    protected const array BUCKETS = ['<10', '10-20', '20-30', '30-40', '40-50', '50-60', '>60'];

    public function __construct(
        protected ScopeFilterBuilder $filter,
    ) {
    }

    /**
     * @return array{0:string,1:string,2:array<string,mixed>}
     */
    protected function buildFromWhere(Scope $scope): array
    {
        return $this->filter->buildBaseFilter($scope);
    }

    protected function timedCte(string $fromSql, string $whereSql): string
    {
        return <<<SQL
WITH timed AS (
  SELECT
    a.*,
    (EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0) AS transport_minutes,
    CASE
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 < 10 THEN '<10'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 >= 10
       AND EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 < 20 THEN '10-20'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 >= 20
       AND EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 < 30 THEN '20-30'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 >= 30
       AND EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 < 40 THEN '30-40'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 >= 40
       AND EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 < 50 THEN '40-50'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 >= 50
       AND EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 <= 60 THEN '50-60'
      WHEN EXTRACT(EPOCH FROM (a.arrival_at - a.created_at)) / 60.0 > 60 THEN '>60'
    END AS t_bucket
  FROM {$fromSql}
  WHERE {$whereSql}
    AND a.arrival_at IS NOT NULL
    AND a.created_at IS NOT NULL
    AND a.arrival_at >= a.created_at
)
SQL;
    }

    protected function coreMetricSelects(): string
    {
        return <<<SQL
COUNT(*)::int                                         AS total,
COUNT(*) FILTER (WHERE is_with_physician)::int        AS with_physician,
COUNT(*) FILTER (WHERE requires_resus):: int          AS resus_req,
COUNT(*) FILTER (WHERE requires_cathlab)::int         AS cathlab_req,
COUNT(*) FILTER (WHERE gender = 'M')::int             AS gender_m,
COUNT(*) FILTER (WHERE gender = 'F')::int             AS gender_w,
COUNT(*) FILTER (WHERE gender = 'X')::int             AS gender_d,
COUNT(*) FILTER (WHERE urgency = 1)::int              AS urg_1,
COUNT(*) FILTER (WHERE urgency = 2)::int              AS urg_2,
COUNT(*) FILTER (WHERE urgency = 3)::int              AS urg_3,
COUNT(*) FILTER (WHERE transport_type = 'G')::int     AS transport_ground,
COUNT(*) FILTER (WHERE transport_type = 'A')::int     AS transport_air
SQL;
    }

    protected function dimColumn(string $dimType): string
    {
        return match ($dimType) {
            'assignment' => 'assignment_id',
            'dispatch_area' => 'dispatch_area_id',
            'occasion' => 'occasion_id',
            'indication' => 'indication_normalized_id',
            'state' => 'state_id',
            'speciality' => 'speciality_id',
            default => throw new \InvalidArgumentException(sprintf('Unknown dimension type "%s"', $dimType)),
        };
    }
}
