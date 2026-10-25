<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Selects the events of a hospital whose volume can change (ADR 015 phase 4):
 * events whose load range [start − before − 6 h, end + after + 6 h) intersects a changed range,
 * events the analysis created or changed, and events that are still provisional, because a
 * coverage change anywhere after them can make them final.
 */
final readonly class ClosureVolumeRebuildPlanner
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     * @param list<int>                                                 $changedEventIds
     *
     * @return list<int>
     */
    public function affectedEvents(int $hospitalId, array $ranges, array $changedEventIds, ClosureVolumeReferenceWindow $window): array
    {
        $froms = [];
        $tos = [];
        foreach ($ranges as [$from, $to]) {
            $froms[] = ClosureVolumeClock::wall($from)->format('Y-m-d H:i:s');
            $tos[] = ClosureVolumeClock::wall($to)->format('Y-m-d H:i:s');
        }

        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT e.id
FROM closure_event e
WHERE e.hospital_id = :hospital
  AND (
      e.id IN (:changed)
      OR EXISTS (
          SELECT 1
          FROM unnest(CAST(:froms AS timestamp[]), CAST(:tos AS timestamp[])) AS changed(range_from, range_to)
          WHERE e.starts_at - make_interval(weeks => :before, hours => :context) < changed.range_to
            AND e.ends_at + make_interval(weeks => :after, hours => :context) > changed.range_from
      )
      OR EXISTS (SELECT 1 FROM closure_volume_hour h WHERE h.event_id = e.id AND h.provisional)
  )
ORDER BY e.id
SQL,
            [
                'hospital' => $hospitalId,
                // An empty IN list is invalid SQL; 0 is never an event id.
                'changed' => [] === $changedEventIds ? [0] : $changedEventIds,
                'froms' => '{'.implode(',', array_map(static fn (string $value): string => '"'.$value.'"', $froms)).'}',
                'tos' => '{'.implode(',', array_map(static fn (string $value): string => '"'.$value.'"', $tos)).'}',
                'before' => $window->weeksBefore,
                'after' => $window->weeksAfter,
                'context' => ClosureVolumeReferenceConfig::CONTEXT_HOURS,
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'changed' => ArrayParameterType::INTEGER,
                'froms' => ParameterType::STRING,
                'tos' => ParameterType::STRING,
                'before' => ParameterType::INTEGER,
                'after' => ParameterType::INTEGER,
                'context' => ParameterType::INTEGER,
            ],
        );

        return array_map(intval(...), $ids);
    }
}
