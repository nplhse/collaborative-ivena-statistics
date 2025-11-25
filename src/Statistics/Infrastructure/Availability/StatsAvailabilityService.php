<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Availability;

use Doctrine\DBAL\Connection;

final class StatsAvailabilityService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{
     *   years: list<int>,
     *   months: list<int>,
     *   hasYear: array<non-empty-string, true>,
     *   hasMonth: array<non-empty-string, true>
     * }
     */
    public function buildMatrix(string $scopeType, string $scopeId): array
    {
        $tables = $this->tablesFor($scopeType);

        $parts = [];
        foreach ($tables as $t) {
            $parts[] = <<<SQL
                SELECT LOWER(period_gran) AS period_gran, period_key::date AS period_key
                  FROM {$t}
                 WHERE scope_type = :t
                   AND scope_id   = :i
                   AND LOWER(period_gran) IN ('year','month','years','months')
            SQL;
        }

        $sql = 'SELECT DISTINCT period_gran, period_key FROM ('
            .implode(' UNION ALL ', $parts)
            .') u';

        $rows = $this->db->fetchAllAssociative($sql, ['t' => $scopeType, 'i' => $scopeId]);

        $hasYear = [];
        $hasMonth = [];
        $yearsSet = [];

        foreach ($rows as $r) {
            $gran = $this->normalizeGran((string) $r['period_gran']);
            $pk = $r['period_key'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($r['period_key'])
                : new \DateTimeImmutable((string) $r['period_key']);

            if ('year' === $gran) {
                $yearKey = $pk->format('Y').'-01-01';
                $hasYear[$yearKey] = true;
                $yearsSet[(int) $pk->format('Y')] = true;
            } elseif ('month' === $gran) {
                $monthKey = $pk->format('Y-m').'-01';
                $hasMonth[$monthKey] = true;
                $yearsSet[(int) $pk->format('Y')] = true;
            }
        }

        $years = array_keys($yearsSet);
        sort($years, \SORT_NUMERIC);

        return [
            'years' => array_map('intval', $years),
            'months' => range(1, 12),
            'hasYear' => $hasYear,
            'hasMonth' => $hasMonth,
        ];
    }

    /**
     * @return list<string>
     */
    private function tablesFor(string $scopeType): array
    {
        $isCohort = \in_array($scopeType, [
            'hospital_tier',
            'hospital_size',
            'hospital_location',
            'hospital_cohort',
        ], true);

        if ($isCohort) {
            return ['agg_allocations_cohort_sums', 'agg_allocations_cohort_stats'];
        }

        return ['agg_allocations_counts'];
    }

    private function normalizeGran(string $g): string
    {
        return match ($g) {
            'months' => 'month',
            'years' => 'year',
            default => $g,
        };
    }
}
