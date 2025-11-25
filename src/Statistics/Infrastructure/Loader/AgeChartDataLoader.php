<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Loader;

use App\Statistics\Domain\Model\Scope;
use Doctrine\DBAL\Connection;

final class AgeChartDataLoader
{
    /** Fixed bucket order used by the calculator and chart alike */
    private const array ORDER = ['<18', '18-29', '30-39', '40-49', '50-59', '60-69', '70-79', '80-89', '90-99'];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param list<array{name:string,col:string}> $metrics
     * @param 'count'|'share'                     $mode
     *
     * @return array{
     *    labels: list<string>,
     *    series: list<array{name:string, data:list<int|float>}>,
     *    mean:   float|null
     *  }
     */
    public function buildPayload(Scope $scope, array $metrics, string $mode = 'count'): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
SELECT
  total, gender_m, gender_w, gender_d,
  urg_1, urg_2, urg_3,
  cathlab_required, resus_required,
  is_cpr, is_ventilated, is_shock, is_pregnant, with_physician, infectious,
  overall_age_mean
FROM agg_allocations_age_buckets
WHERE scope_type = :t AND scope_id = :i AND period_gran = :g AND period_key = :k::date
LIMIT 1
SQL,
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        /** @var list<array{key:string, n?:int, share?:float}> $empty */
        $empty = [];

        /**
         * @psalm-return list<array{key:string, n?:int, share?:float}>
         */
        $decode = static function (?string $json) use ($empty): array {
            if (!\is_string($json) || '' === $json) {
                return $empty;
            }
            $data = json_decode($json, true);
            if (!\is_array($data)) {
                return $empty;
            }

            // best-effort sanitize to the expected shape
            $out = [];
            foreach ($data as $b) {
                if (!\is_array($b)) {
                    continue;
                }
                $key = isset($b['key']) && \is_string($b['key']) ? $b['key'] : null;
                if (null === $key) {
                    continue;
                }
                $entry = ['key' => $key];
                if (isset($b['n']) && \is_int($b['n'])) {
                    $entry['n'] = $b['n'];
                }
                if (array_key_exists('share', $b) && (\is_float($b['share']) || \is_int($b['share']))) {
                    $sv = $b['share'];
                    $entry['share'] = (float) $sv;
                }
                /* @var array{key:string, n?:int, share?:float} $entry */
                $out[] = $entry;
            }

            return $out;
        };

        $series = [];
        foreach ($metrics as $m) {
            $col = $m['col'];
            $bucketArr = $decode(isset($row[$col]) && \is_string($row[$col]) ? $row[$col] : null);

            /**
             * Index by key for O(1) lookups.
             *
             * @var array<string, array{key:string, n?:int, share?:float}> $byKey
             */
            $byKey = [];
            foreach ($bucketArr as $b) {
                $byKey[$b['key']] = $b;
            }

            $data = [];
            foreach (self::ORDER as $key) {
                $b = $byKey[$key] ?? null;

                if ('share' === $mode) {
                    // Guard against null; only access array when present
                    if (null === $b) {
                        $data[] = 0.0;
                    } else {
                        $share = ($b['share'] ?? 0.0);
                        $data[] = $share;
                    }
                } else { // 'count'
                    if (null === $b) {
                        $data[] = 0;
                    } else {
                        $n = ($b['n'] ?? 0);
                        $data[] = $n;
                    }
                }
            }

            $series[] = [
                'name' => $m['name'],
                'data' => $data,
            ];
        }

        return [
            'labels' => self::ORDER,
            'series' => $series,
            'mean' => isset($row['overall_age_mean']) ? (float) $row['overall_age_mean'] : null,
        ];
    }
}
