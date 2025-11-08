<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use Doctrine\DBAL\Connection;

final readonly class HourlyStatsService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @return array{
     *   labels: list<int>,
     *   series: array<string, list<int>>,
     *   computedAt: \DateTimeImmutable|null
     * }
     */
    public function fetchHourly(Scope $scope): array
    {
        /** @var array{
         *   hours_count?: mixed,
         *   computed_at?: string|null
         * }|false $row
         */
        $row = $this->db->fetchAssociative(
            <<<SQL
              SELECT hours_count, computed_at
                FROM agg_allocations_hourly
               WHERE scope_type = :t
                 AND scope_id   = :i
                 AND period_gran = :g
                 AND period_key  = :k::date
               LIMIT 1
            SQL,
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        /** @var list<int> $labels */
        $labels = range(0, 23);

        /** @var array<string, list<int>> $series */
        $series = [];

        $computedAt = null;

        if (false !== $row && array_key_exists('hours_count', $row)) {
            $raw = $row['hours_count'];

            if (is_string($raw)) {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $decoded = $raw;
            }

            if (is_array($decoded)) {
                foreach ($decoded as $key => $vals) {
                    if (is_array($vals) && 24 === count($vals)) {
                        /** @var list<int> $asList */
                        $asList = array_values(array_map('intval', $vals));

                        $series[(string) $key] = $asList;
                    }
                }
            }
        }

        if (false !== $row
            && array_key_exists('computed_at', $row)
            && is_string($row['computed_at'])
            && '' !== $row['computed_at']
        ) {
            $computedAt = new \DateTimeImmutable($row['computed_at']);
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'computedAt' => $computedAt,
        ];
    }
}
