<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Diagnostics;

use App\Statistics\Application\Contract\ClosureVolumeDiagnosticsInterface;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureCoverageQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureVolumeDiagnostics implements ClosureVolumeDiagnosticsInterface
{
    public function __construct(
        private Connection $connection,
        private ClosureCoverageQuery $coverage,
    ) {
    }

    #[\Override]
    public function referenceQuality(?int $hospitalId): array
    {
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT hospital_id, scope,
       COUNT(*)::int AS rows,
       COUNT(*) FILTER (WHERE reference_mode = 'weekday_hour')::int AS weekday_hour,
       COUNT(*) FILTER (WHERE reference_mode = 'day_time_bucket')::int AS day_time_bucket,
       COUNT(*) FILTER (WHERE reference_mode = 'insufficient')::int AS insufficient,
       COUNT(DISTINCT event_id) FILTER (WHERE reference_mode = 'insufficient' AND in_closure)::int AS events_with_insufficient_closure_rows
FROM closure_volume_hour
WHERE stratum = 'base'
  AND (CAST(:hospital AS int) IS NULL OR hospital_id = CAST(:hospital AS int))
GROUP BY hospital_id, scope
ORDER BY hospital_id, scope
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => null === $hospitalId ? ParameterType::NULL : ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'hospital_id' => (int) $row['hospital_id'],
            'scope' => (string) $row['scope'],
            'rows' => (int) $row['rows'],
            'weekday_hour' => (int) $row['weekday_hour'],
            'day_time_bucket' => (int) $row['day_time_bucket'],
            'insufficient' => (int) $row['insufficient'],
            'events_with_insufficient_closure_rows' => (int) $row['events_with_insufficient_closure_rows'],
        ], $rows);
    }

    #[\Override]
    public function coverage(?int $hospitalId): array
    {
        $result = [];
        foreach ($this->hospitalIds($hospitalId) as $id) {
            foreach (['allocations' => $this->coverage->allocations($id), 'closures' => $this->coverage->closures($id)] as $kind => $coverage) {
                $ranges = $coverage?->ranges() ?? [];
                $result[] = [
                    'hospital_id' => $id,
                    'kind' => $kind,
                    'derived' => $coverage instanceof ClosureCoverage,
                    'ranges' => \count($ranges),
                    'first_start' => [] === $ranges ? null : $ranges[0][0]->format('Y-m-d H:i'),
                    'last_end' => [] === $ranges ? null : $ranges[\count($ranges) - 1][1]->format('Y-m-d H:i'),
                    'gaps' => array_map(
                        static fn (array $gap): string => $gap[0]->format('Y-m-d H:i').' → '.$gap[1]->format('Y-m-d H:i'),
                        $coverage?->gaps() ?? [],
                    ),
                ];
            }
        }

        return $result;
    }

    #[\Override]
    public function exportBoundaries(?int $hospitalId): array
    {
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH import_end AS (
    SELECT import_id, MAX(ends_at) AS last_end
    FROM closure_interval
    GROUP BY import_id
), at_end AS (
    SELECT ci.*
    FROM closure_interval ci
    INNER JOIN import_end ie ON ie.import_id = ci.import_id AND ie.last_end = ci.ends_at
    WHERE CAST(:hospital AS int) IS NULL OR ci.hospital_id = CAST(:hospital AS int)
)
SELECT h.hospital_id,
       (SELECT COUNT(*) FROM at_end a WHERE a.hospital_id = h.hospital_id)::int AS intervals_at_import_end,
       (SELECT COUNT(*) FROM at_end a WHERE a.hospital_id = h.hospital_id AND EXISTS (
            SELECT 1 FROM closure_interval nxt
            WHERE nxt.hospital_id = a.hospital_id
              AND nxt.department_id = a.department_id
              AND nxt.import_id <> a.import_id
              AND nxt.starts_at = a.ends_at
       ))::int AS with_continuation,
       (SELECT COUNT(*) FROM at_end a WHERE a.hospital_id = h.hospital_id
            AND a.ends_at = date_trunc('month', a.ends_at))::int AS at_month_boundary,
       (SELECT COUNT(*) FROM closure_relation_candidate rc
            WHERE rc.hospital_id = h.hospital_id AND rc.kind = 'export_boundary')::int AS export_boundary_relations
FROM (SELECT DISTINCT hospital_id FROM closure_interval
      WHERE CAST(:hospital AS int) IS NULL OR hospital_id = CAST(:hospital AS int)) h
ORDER BY h.hospital_id
SQL,
            ['hospital' => $hospitalId],
            ['hospital' => null === $hospitalId ? ParameterType::NULL : ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'hospital_id' => (int) $row['hospital_id'],
            'intervals_at_import_end' => (int) $row['intervals_at_import_end'],
            'with_continuation' => (int) $row['with_continuation'],
            'at_month_boundary' => (int) $row['at_month_boundary'],
            'export_boundary_relations' => (int) $row['export_boundary_relations'],
        ], $rows);
    }

    #[\Override]
    public function provisional(?int $hospitalId, int $weeks): array
    {
        $result = [];
        foreach ($this->hospitalIds($hospitalId) as $id) {
            $allocations = $this->coverage->allocations($id);
            $closures = $this->coverage->closures($id);
            /** @var list<array{starts_at: string, ends_at: string}> $events */
            $events = $this->connection->fetchAllAssociative(
                'SELECT starts_at, ends_at FROM closure_event WHERE hospital_id = :hospital',
                ['hospital' => $id],
                ['hospital' => ParameterType::INTEGER],
            );
            $row = [
                'hospital_id' => $id,
                'events' => \count($events),
                'final' => 0,
                'awaiting_allocations' => 0,
                'awaiting_closures' => 0,
                'incomplete_before' => 0,
                'not_derived' => !$allocations instanceof ClosureCoverage || !$closures instanceof ClosureCoverage,
            ];
            if (!$allocations instanceof ClosureCoverage || !$closures instanceof ClosureCoverage) {
                $result[] = $row;
                continue;
            }
            foreach ($events as $event) {
                $start = ClosureVolumeClock::wall(new \DateTimeImmutable($event['starts_at']));
                $end = ClosureVolumeClock::wall(new \DateTimeImmutable($event['ends_at']));
                $after = $end->modify(sprintf('+%d weeks', $weeks));
                $before = $start->modify(sprintf('-%d weeks', $weeks));
                $awaitingAllocations = !$allocations->covers($end, $after);
                $awaitingClosures = !$closures->covers($end, $after);
                $row['awaiting_allocations'] += $awaitingAllocations ? 1 : 0;
                $row['awaiting_closures'] += $awaitingClosures ? 1 : 0;
                $row['incomplete_before'] += $allocations->covers($before, $start) ? 0 : 1;
                $row['final'] += $awaitingAllocations || $awaitingClosures ? 0 : 1;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function hospitalIds(?int $hospitalId): array
    {
        if (null !== $hospitalId) {
            return [$hospitalId];
        }
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT DISTINCT hospital_id FROM closure_event ORDER BY hospital_id');

        return array_map(intval(...), $ids);
    }
}
