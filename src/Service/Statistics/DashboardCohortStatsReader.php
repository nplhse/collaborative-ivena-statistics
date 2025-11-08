<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\CohortRate;
use App\Model\CohortStatsView;
use App\Model\Scope;
use Doctrine\DBAL\Connection;

/** @psalm-suppress ClassMustBeFinal */
readonly class DashboardCohortStatsReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(private Connection $db)
    {
    }

    public function read(Scope $scope): ?CohortStatsView
    {
        $row = $this->db->fetchAssociative(
            'SELECT n, mean_total, rates, computed_at
               FROM agg_allocations_cohort_stats
              WHERE scope_type = :t AND scope_id = :i
                AND period_gran = :g AND period_key = :k',
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        if (false === $row) {
            return null;
        }

        /** @var array<string, array{mean: float|string|int|null, sd: float|string|int|null, var: float|string|int|null}> $ratesRaw */
        $ratesRaw = \json_decode((string) $row['rates'], true, flags: \JSON_THROW_ON_ERROR);

        $rates = [];
        foreach ($ratesRaw as $metric => $triplet) {
            $rates[$metric] = new CohortRate(
                mean: self::numOrNull($triplet['mean']),
                sd: self::numOrNull($triplet['sd']),
                var: self::numOrNull($triplet['var']),
            );
        }

        return new CohortStatsView(
            scope: $scope,
            n: (int) $row['n'],
            meanTotal: (float) $row['mean_total'],
            computedAt: new \DateTimeImmutable((string) $row['computed_at']),
            rates: $rates,
        );
    }

    private static function numOrNull(mixed $v): ?float
    {
        if (null === $v) {
            return null;
        }

        // normalize possible numeric strings/ints
        return is_numeric($v) ? (float) $v : null;
    }
}
