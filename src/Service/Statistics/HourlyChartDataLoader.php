<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use Doctrine\DBAL\Connection;

final readonly class HourlyChartDataLoader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @param list<string> $metrics
     *
     * @return array{
     *   labels: list<string>,
     *   series: list<array{name:string,data:list<int>}>,
     *   meta: array{scopeType:string,scopeId:string,gran:string,key:string,available:list<string>}
     * }
     */
    public function buildPayload(Scope $scope, array $metrics = ['total']): array
    {
        /** @var array{hours_count?: mixed}|false $row */
        $row = $this->db->fetchAssociative(
            <<<SQL
        SELECT hours_count
          FROM agg_allocations_hourly
         WHERE scope_type = :t
           AND scope_id   = :i
           AND period_gran = :g
           AND period_key  = :k::date
        SQL,
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        /** @var array<string, mixed> $obj */
        $obj = [];

        if (false !== $row && array_key_exists('hours_count', $row)) {
            $raw = $row['hours_count'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } else {
                $decoded = $raw;
            }
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $obj = $decoded;
            }
        }

        $available = array_keys($obj);

        $series = [];
        foreach ($metrics as $metric) {
            $storeKey = $this->storageKeyFor($metric);
            $data = $this->normalize24($obj[$storeKey] ?? null);
            $series[] = [
                'name' => $this->labelForMetric($metric),
                'data' => $data,
            ];
        }

        return [
            'labels' => $this->hourLabels(),
            'series' => $series,
            'meta' => [
                'scopeType' => $scope->scopeType,
                'scopeId' => $scope->scopeId,
                'gran' => $scope->granularity,
                'key' => $scope->periodKey,
                'available' => $available,
            ],
        ];
    }

    /**
     * @return list<string> "00"…"23"
     */
    private function hourLabels(): array
    {
        $out = [];
        for ($h = 0; $h < 24; ++$h) {
            $out[] = sprintf('%02d', $h);
        }

        return $out;
    }

    private function storageKeyFor(string $metric): string
    {
        return match ($metric) {
            'cathlab' => 'cathlab_required',
            'resus' => 'resus_required',
            default => $metric,
        };
    }

    /**
     * @return list<int>
     */
    private function normalize24(mixed $v): array
    {
        if (!is_array($v)) {
            /* @var list<int> */
            return array_fill(0, 24, 0);
        }

        $vals = array_values(array_map(static fn ($x): int => (int) $x, $v));
        $count = count($vals);

        if (24 === $count) {
            return $vals;
        }
        if ($count > 24) {
            /* @var list<int> */
            return array_slice($vals, 0, 24);
        }

        /* @var list<int> */
        return array_pad($vals, 24, 0);
    }

    private function labelForMetric(string $metric): string
    {
        return match ($metric) {
            'total' => 'Total',
            'gender_m' => 'Male',
            'gender_w' => 'Female',
            'gender_d' => 'Diverse',
            'urg_1' => 'Urgency 1',
            'urg_2' => 'Urgency 2',
            'urg_3' => 'Urgency 3',
            'cathlab' => 'Cathlab required',
            'resus' => 'Resus required',
            'is_cpr' => 'CPR',
            'is_ventilated' => 'Ventilated',
            'is_shock' => 'Shock',
            'is_pregnant' => 'Pregnant',
            'with_physician' => 'With Physician',
            'infectious' => 'Infectious',
            default => ucfirst(str_replace('_', ' ', $metric)),
        };
    }
}
