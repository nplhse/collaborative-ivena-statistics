<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;

/** @psalm-suppress ClassMustBeFinal */
class ScopeAvailabilityService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(private Connection $db)
    {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return array{
     *   all:    list<string>,
     *   year:   list<string>,
     *   quarter:list<string>,
     *   month:  list<string>,
     *   week:   list<string>,
     *   day:    list<string>
     * }
     */
    public function getMatrix(Scope $scope): array
    {
        $table = $this->tableFor($scope->scopeType);

        $rows = $this->db->fetchAllAssociative(
            <<<SQL
            SELECT period_gran, period_key::text AS k
              FROM {$table}
             WHERE scope_type = :t
               AND scope_id   = :i
            GROUP BY period_gran, period_key
            ORDER BY period_gran, period_key
            SQL,
            ['t' => $scope->scopeType, 'i' => $scope->scopeId]
        );

        $out = [
            Period::ALL => [],
            Period::YEAR => [],
            Period::QUARTER => [],
            Period::MONTH => [],
            Period::WEEK => [],
            Period::DAY => [],
        ];

        foreach ($rows as $r) {
            $g = $r['period_gran'];
            $k = $r['k'];
            if (isset($out[$g])) {
                $out[$g][] = $k;
            }
        }

        return $out;
    }

    /**
     * @return list<array{
     *   scope_type: non-empty-string,
     *   scope_id: string,
     *   count: int
     * }>
     */
    public function getSidebarTree(): array
    {
        $qCounts = <<<SQL
        SELECT scope_type, scope_id, COUNT(*) AS cnt
          FROM agg_allocations_counts
         GROUP BY scope_type, scope_id
    SQL;

        $qCohorts = <<<SQL
        SELECT scope_type, scope_id, COUNT(*) AS cnt
          FROM agg_allocations_cohort_sums
         GROUP BY scope_type, scope_id
    SQL;

        $rowsCounts = $this->db->fetchAllAssociative($qCounts);
        $rowsCohorts = $this->db->fetchAllAssociative($qCohorts);

        /** @var array<string, array{scope_type: non-empty-string, scope_id: string, count: int}> $acc */
        $acc = [];

        $ingest = static function (array $rows) use (&$acc): void {
            foreach ($rows as $r) {
                $type = isset($r['scope_type']) ? (string) $r['scope_type'] : '';
                if ('' === $type) {
                    continue;
                }
                $id = (string) ($r['scope_id'] ?? '');
                $count = (int) ($r['count'] ?? ($r['cnt'] ?? 0));

                $key = $type.'|'.$id;

                if (isset($acc[$key])) {
                    $acc[$key]['count'] += $count;
                } else {
                    $acc[$key] = [
                        'scope_type' => $type,
                        'scope_id' => $id,
                        'count' => $count,
                    ];
                }
            }
        };

        $ingest($rowsCounts);
        $ingest($rowsCohorts);

        return array_values($acc);
    }

    private function tableFor(string $scopeType): string
    {
        return \in_array($scopeType, ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'], true)
            ? 'agg_allocations_cohort_sums'
            : 'agg_allocations_counts';
    }
}
