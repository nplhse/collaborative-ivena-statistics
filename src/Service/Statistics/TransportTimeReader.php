<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use App\Model\TransportTimeMetricRow;
use App\Model\TransportTimeStatsView;
use App\Repository\AggScopeRepository;
use Doctrine\DBAL\Connection;

/**
 * Reads precomputed transport time bucket statistics for a given scope
 * and maps them into simple typed read models.
 */
final class TransportTimeReader
{
    /**
     * Transport time bucket keys in a fixed display order.
     */
    private const BUCKETS = ['<10', '10-20', '20-30', '30-40', '40-50', '50-60', '>60'];

    /**
     * Mapping of metric keys to human-readable labels.
     *
     * @var array<string,string>
     */
    private const METRIC_LABELS = [
        'total' => 'Total',
        'with_physician' => 'With physician',
        'gender_m' => 'Male',
        'gender_w' => 'Female',
        'gender_d' => 'Diverse',
        'urg_1' => 'Urgency 1',
        'urg_2' => 'Urgency 2',
        'urg_3' => 'Urgency 3',
        'transport_ground' => 'Ground transport',
        'transport_air' => 'Air transport',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly Connection $db,
        private readonly AggScopeRepository $aggScopeRepository,
    ) {
    }

    /**
     * Load transport time statistics for a given scope and return a typed view model.
     */
    public function read(Scope $scope): TransportTimeStatsView
    {
        // 1) Resolve agg_scope_id (do not create new scopes here)
        $aggScopeId = $this->aggScopeRepository->findIdForScope($scope);

        $emptyRows = $this->buildEmptyRows();

        if (null === $aggScopeId) {
            return TransportTimeStatsView::empty(self::BUCKETS, $emptyRows);
        }

        // 2) Load the aggregated row for this scope
        $sql = <<<SQL
SELECT
  total,
  with_physician,
  gender_m,
  gender_w,
  gender_d,
  urg_1,
  urg_2,
  urg_3,
  transport_ground,
  transport_air,
  overall_minutes_mean,
  overall_minutes_variance,
  overall_minutes_stddev,
  computed_at
FROM agg_allocations_transport_time_buckets
WHERE agg_scope_id = :sid
LIMIT 1;
SQL;

        $row = $this->db->fetchAssociative($sql, ['sid' => $aggScopeId]);

        if (false === $row) {
            return TransportTimeStatsView::empty(self::BUCKETS, $emptyRows);
        }

        // 3) Decode JSON metric arrays and map to metric rows
        $metricRows = [];
        foreach (self::METRIC_LABELS as $metricKey => $label) {
            $json = $row[$metricKey] ?? '[]';
            $raw = \is_string($json)
                ? json_decode($json, true, 512, JSON_THROW_ON_ERROR)
                : (array) $json;

            $values = $this->normalizeBucketValues($raw);

            $metricRows[] = new TransportTimeMetricRow(
                $metricKey,
                $label,
                $values
            );
        }

        // 4) Overall statistics
        $mean = isset($row['overall_minutes_mean'])
            ? (float) $row['overall_minutes_mean']
            : null;
        $variance = isset($row['overall_minutes_variance'])
            ? (float) $row['overall_minutes_variance']
            : null;
        $stddev = isset($row['overall_minutes_stddev'])
            ? (float) $row['overall_minutes_stddev']
            : null;

        // 5) Computed timestamp
        $computedAt = null;
        if (!empty($row['computed_at'])) {
            $computedAt = new \DateTimeImmutable((string) $row['computed_at']);
        }

        return new TransportTimeStatsView(
            self::BUCKETS,
            $metricRows,
            $mean,
            $variance,
            $stddev,
            $computedAt
        );
    }

    /**
     * Build rows with zeroed values used when no data is available.
     *
     * @return TransportTimeMetricRow[]
     */
    private function buildEmptyRows(): array
    {
        $rows = [];
        foreach (self::METRIC_LABELS as $metricKey => $label) {
            $values = [];
            foreach (self::BUCKETS as $bucketKey) {
                $values[] = [
                    'bucket' => $bucketKey,
                    'count' => 0,
                    'share' => 0.0,
                ];
            }

            $rows[] = new TransportTimeMetricRow($metricKey, $label, $values);
        }

        return $rows;
    }

    /**
     * Normalize raw JSON bucket arrays into a fixed set of values,
     * one per bucket, in the defined order.
     *
     * @param array<int,array{key:string,n:int,share:float}> $raw
     *
     * @return array<int,array{bucket:string,count:int,share:float}>
     */
    private function normalizeBucketValues(array $raw): array
    {
        $byKey = [];
        foreach ($raw as $item) {
            if (!isset($item['key'])) {
                continue;
            }
            $byKey[$item['key']] = $item;
        }

        $values = [];
        foreach (self::BUCKETS as $bucketKey) {
            $item = $byKey[$bucketKey] ?? null;
            $count = isset($item['n']) ? $item['n'] : 0;
            $share = isset($item['share']) ? $item['share'] : 0.0;

            $values[] = [
                'bucket' => $bucketKey,
                'count' => $count,
                'share' => $share,
            ];
        }

        return $values;
    }
}
