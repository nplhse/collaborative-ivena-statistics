<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourPlanner;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeInterval;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumePopulation;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRate;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceCalendar;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSelector;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rebuilds closure_volume_hour from persisted events.
 * One hospital is split into weekly partitions so PHP does not hold the whole hospital.
 * The published table is replaced only after the build table is complete.
 */
final class ClosureVolumeProjectionRebuilder
{
    private const int INSERT_BATCH = 200;

    public int $maxPartitionBytes = 0;

    /** @var list<int> */
    public array $partitionSamples = [];

    /** @var \Closure(): void|null */
    public ?\Closure $beforePublishHook = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClosureVolumeReferenceConfig $config,
        private readonly ClosureVolumeReferenceCalendar $calendar,
        private readonly ClosureVolumeHourPlanner $planner,
        private readonly ClosureVolumeReferenceSelector $selector,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly ?DebugDataHolder $debugDataHolder = null,
        #[Autowire(service: 'monolog.logger.doctrine')]
        private readonly ?LoggerInterface $doctrineLogger = null,
    ) {
    }

    /**
     * @param callable(int $processed, int $total): void|null                      $onProgress
     * @param list<int>|null                                                       $hospitalIds     null replaces the whole projection
     * @param callable(list<int>|null): array{full: bool, refresh: list<int>}|null $expandHospitals
     */
    public function rebuild(?callable $onProgress = null, ?array $hospitalIds = null, ?callable $expandHospitals = null): int
    {
        $this->maxPartitionBytes = 0;
        $this->partitionSamples = [];
        $silenced = $this->silenceDoctrineLog();
        $full = null === $hospitalIds;

        try {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour_build');
            $this->createBuildTable();
            $ids = $hospitalIds ?? $this->hospitalIds();
            $pending = [];
            $done = 0;
            $total = $this->countEvents($ids);
            if (null !== $onProgress) {
                $onProgress(0, $total);
            }
            foreach ($ids as $hospitalId) {
                $before = memory_get_usage(false);
                $pending = $this->rebuildHospital($hospitalId, $pending, $done, $total, $onProgress);
                $this->forgetDebugQueries();
                gc_collect_cycles();
                $this->sample('hospital '.$hospitalId, 0.0, max(0, memory_get_usage(false) - $before));
            }
            $this->flush($pending, true);
            unset($pending);
            if (null !== $expandHospitals) {
                $decision = $expandHospitals($full ? null : $ids);
                $refresh = $decision['refresh'];
                if ([] !== $refresh) {
                    /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
                    $this->connection->executeStatement(
                        'DELETE FROM closure_volume_hour_build WHERE hospital_id IN (:ids)',
                        ['ids' => $refresh],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }
                $pending = [];
                foreach ($refresh as $hospitalId) {
                    $pending = $this->rebuildHospital($hospitalId, $pending, $done, $total, $onProgress);
                }
                if ($decision['full']) {
                    $known = array_values(array_unique([...$ids, ...$refresh]));
                    foreach (array_values(array_diff($this->hospitalIds(), $known)) as $hospitalId) {
                        $pending = $this->rebuildHospital($hospitalId, $pending, $done, $total, $onProgress);
                    }
                    $full = true;
                    $ids = $this->hospitalIds();
                } elseif ([] !== $refresh) {
                    $ids = array_values(array_unique([...$ids, ...$refresh]));
                }
                $this->flush($pending, true);
                unset($pending, $refresh, $decision);
            }
            if ($this->beforePublishHook instanceof \Closure) {
                ($this->beforePublishHook)();
            }
            if (null !== $onProgress) {
                $onProgress($total, $total);
            }
            $this->publish($full, $ids);
        } catch (\Throwable $exception) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour_build');
            throw $exception;
        } finally {
            $this->restoreDoctrineLog($silenced);
        }

        return \count($ids);
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function publish(bool $full, array $hospitalIds): void
    {
        if ($full) {
            $this->swap();

            return;
        }
        if ([] === $hospitalIds) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour_build');

            return;
        }
        $this->connection->transactional(function () use ($hospitalIds): void {
            /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
            $this->connection->executeStatement(
                'DELETE FROM closure_volume_hour WHERE hospital_id IN (:ids)',
                ['ids' => $hospitalIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $this->connection->executeStatement(
                <<<'SQL'
INSERT INTO closure_volume_hour (
    event_id, scope, hospital_id, speciality_id, department_id, urgency_code, stratum,
    bucket_start, bucket_end, in_closure, observed_area, expected_area, observed_hospital,
    expected_hospital, evaluable_seconds, bucket_seconds, reference_slot_count,
    reference_assignment_count, reference_mode, influenced, quality, reference_cutoff
)
SELECT event_id, scope, hospital_id, speciality_id, department_id, urgency_code, stratum,
       bucket_start, bucket_end, in_closure, observed_area, expected_area, observed_hospital,
       expected_hospital, evaluable_seconds, bucket_seconds, reference_slot_count,
       reference_assignment_count, reference_mode, influenced, quality, reference_cutoff
FROM closure_volume_hour_build
SQL,
            );
            $this->connection->executeStatement('DROP TABLE closure_volume_hour_build');
        });
    }

    /**
     * @param list<ClosureVolumeHourDraft>                    $pending
     * @param callable(int $processed, int $total): void|null $onProgress
     *
     * @return list<ClosureVolumeHourDraft>
     */
    private function rebuildHospital(int $hospitalId, array $pending, int &$done, int $total, ?callable $onProgress): array
    {
        $bounds = $this->connection->fetchAssociative(
            'SELECT MIN(starts_at) AS min_start, MAX(starts_at) AS max_start FROM closure_event WHERE hospital_id = :hospital',
            ['hospital' => $hospitalId],
            ['hospital' => ParameterType::INTEGER],
        );
        if (!\is_array($bounds) || !\is_string($bounds['min_start']) || '' === $bounds['min_start']) {
            return $pending;
        }

        $weekStart = new \DateTimeImmutable($bounds['min_start'])->setTime(0, 0);
        $last = new \DateTimeImmutable((string) $bounds['max_start'])->modify('+1 day');
        while ($weekStart < $last) {
            $weekEnd = $weekStart->modify('+7 days');
            $pending = $this->rebuildPartition($hospitalId, $weekStart, $weekEnd, $pending, $done, $total, $onProgress);
            $weekStart = $weekEnd;
        }

        return $pending;
    }

    /**
     * @param list<ClosureVolumeHourDraft>                    $pending
     * @param callable(int $processed, int $total): void|null $onProgress
     *
     * @return list<ClosureVolumeHourDraft>
     */
    private function rebuildPartition(
        int $hospitalId,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd,
        array $pending,
        int &$done,
        int $total,
        ?callable $onProgress,
    ): array {
        $started = microtime(true);
        $before = memory_get_usage(false);
        $anchors = $this->anchors($hospitalId, $weekStart, $weekEnd);
        if ([] === $anchors) {
            return $pending;
        }

        $now = ClosureVolumeClock::now();
        $from = $anchors[0]->startsAt;
        $to = $anchors[0]->endsAt;
        foreach ($anchors as $anchor) {
            if ($anchor->startsAt < $from) {
                $from = $anchor->startsAt;
            }
            if ($anchor->endsAt > $to) {
                $to = $anchor->endsAt;
            }
        }
        $windowStart = $from
            ->modify(sprintf('-%d weeks', $this->config->referenceWeeks))
            ->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        $windowEnd = $to->modify(sprintf('+%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        if ($windowEnd > $now) {
            $windowEnd = $now;
        }
        if ($windowStart >= $windowEnd) {
            $windowEnd = $windowStart->modify('+1 second');
        }

        $neighbours = $this->neighbours($hospitalId, $windowStart, $windowEnd);
        [$areaCounts, $hospitalCounts, $coveredDays] = $this->hourlyCounts($hospitalId, $windowStart, $windowEnd);
        $this->fillPartialCounts($hospitalId, $anchors, $now, $areaCounts, $hospitalCounts);
        [$blockedDepartment, $blockedSpeciality] = $this->blockedMaps($neighbours, $windowStart, $windowEnd);
        /** @var array<string, array<string, ClosureVolumeRate>> $rateCache */
        $rateCache = [];

        $seenEvents = [];
        foreach ($anchors as $anchor) {
            $groups = $this->referenceGroups($anchor, $now);
            $pending = array_merge($pending, $this->planner->plan(
                $anchor,
                $now,
                $areaCounts,
                $hospitalCounts,
                $this->referenceRates($anchor, $groups, $areaCounts, $coveredDays, $blockedDepartment, $blockedSpeciality, $rateCache),
                $this->referenceRates($this->hospitalAnchor($anchor), $groups, $hospitalCounts, $coveredDays, [], [], $rateCache),
                $coveredDays,
                $neighbours,
            ));
            if (\count($pending) >= self::INSERT_BATCH) {
                $pending = $this->flush($pending, false);
            }
            $seenEvents[$anchor->eventId] = true;
        }
        $done += \count($seenEvents);
        if (null !== $onProgress) {
            $onProgress(min($done, $total), $total);
        }

        unset($anchors, $neighbours, $areaCounts, $hospitalCounts, $coveredDays, $blockedDepartment, $blockedSpeciality, $rateCache, $seenEvents);
        gc_collect_cycles();
        $retained = max(0, memory_get_usage(false) - $before);
        $this->partitionSamples[] = $retained;
        $this->sample($hospitalId.' '.$weekStart->format('Y-m-d'), microtime(true) - $started, $retained);

        return $pending;
    }

    private function hospitalAnchor(ClosureVolumeInterval $anchor): ClosureVolumeInterval
    {
        return new ClosureVolumeInterval(
            $anchor->id,
            $anchor->hospitalId,
            0,
            $anchor->urgencies,
            $anchor->startsAt,
            $anchor->endsAt,
            $anchor->careLevel,
            0,
            'hospital',
            $anchor->eventId,
            $anchor->segments,
        );
    }

    /**
     * @return list<ClosureVolumeInterval>
     */
    private function anchors(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT e.id AS event_id, ai.speciality_id, ai.department_id, ai.starts_at, ai.ends_at, cl.care_level
FROM closure_event e
INNER JOIN closure_analysis_interval ai ON ai.event_id = e.id
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
WHERE e.hospital_id = :hospital
  AND e.starts_at >= :from
  AND e.starts_at < :to
ORDER BY e.id ASC, ai.starts_at ASC
SQL,
            [
                'hospital' => $hospitalId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'from' => ParameterType::STRING,
                'to' => ParameterType::STRING,
            ],
        );

        /** @var array<int, list<array{speciality: int, department: int, start: \DateTimeImmutable, end: \DateTimeImmutable, urgencies: list<int>}>> $members */
        $members = [];
        foreach ($rows as $row) {
            $members[(int) $row['event_id']][] = [
                'speciality' => (int) $row['speciality_id'],
                'department' => (int) $row['department_id'],
                'start' => ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['starts_at'])),
                'end' => ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['ends_at'])),
                'urgencies' => ClosureVolumePopulation::urgenciesForCareLevel((string) $row['care_level']),
            ];
        }

        $anchors = [];
        foreach ($members as $eventId => $eventMembers) {
            /** @var array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $departmentSegments */
            $departmentSegments = [];
            /** @var array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $specialitySegments */
            $specialitySegments = [];
            /** @var array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $hospitalSegments */
            $hospitalSegments = [];
            /** @var array<string, array{speciality: int, department: int, urgency: int}> $departmentMeta */
            $departmentMeta = [];
            foreach ($eventMembers as $member) {
                foreach ($member['urgencies'] as $urgency) {
                    $departmentKey = $member['speciality'].'|'.$member['department'].'|'.$urgency;
                    $departmentSegments[$departmentKey][] = [$member['start'], $member['end']];
                    $departmentMeta[$departmentKey] = [
                        'speciality' => $member['speciality'],
                        'department' => $member['department'],
                        'urgency' => $urgency,
                    ];
                    $specialitySegments[$member['speciality'].'|'.$urgency][] = [$member['start'], $member['end']];
                    $hospitalSegments[$urgency][] = [$member['start'], $member['end']];
                }
            }
            foreach ($departmentSegments as $key => $segments) {
                $meta = $departmentMeta[$key];
                $anchors[] = $this->anchor($eventId, $hospitalId, 'department', $meta['speciality'], $meta['department'], [$meta['urgency']], $segments);
            }
            foreach ($specialitySegments as $key => $segments) {
                [$specialityId, $urgency] = array_map(intval(...), explode('|', $key));
                $anchors[] = $this->anchor($eventId, $hospitalId, 'speciality', $specialityId, 0, [$urgency], $segments);
            }
            foreach ($hospitalSegments as $urgency => $segments) {
                $anchors[] = $this->anchor($eventId, $hospitalId, 'hospital', 0, 0, [$urgency], $segments);
            }
        }
        unset($rows, $members);

        return $anchors;
    }

    /**
     * @param list<int>                                                 $urgencies
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments
     */
    private function anchor(
        int $eventId,
        int $hospitalId,
        string $scope,
        int $specialityId,
        int $departmentId,
        array $urgencies,
        array $segments,
    ): ClosureVolumeInterval {
        $merged = $this->unionSegments($segments);
        if ([] === $merged) {
            throw new \InvalidArgumentException('A closure volume interval needs at least one segment.');
        }
        $first = $merged[array_key_first($merged)];
        $last = $merged[array_key_last($merged)];

        return new ClosureVolumeInterval(
            $eventId,
            $hospitalId,
            $departmentId,
            $urgencies,
            $first[0],
            $last[1],
            'merged',
            $specialityId,
            $scope,
            $eventId,
            $merged,
        );
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function unionSegments(array $segments): array
    {
        usort($segments, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        $merged = [];
        foreach ($segments as $segment) {
            $start = $segment[0];
            $end = $segment[1];
            $last = array_key_last($merged);
            if (null === $last || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
                continue;
            }
            if ($end > $merged[$last][1]) {
                $current = $merged[$last];
                $merged[$last] = [$current[0], $end];
            }
        }

        return array_values($merged);
    }

    /**
     * @return list<ClosureVolumeInterval>
     */
    private function neighbours(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT ai.id, ai.event_id, ai.speciality_id, ai.department_id, ai.starts_at, ai.ends_at,
       string_agg(cl.care_level, ',') AS care_levels
FROM closure_analysis_interval ai
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
WHERE ai.hospital_id = :hospital
  AND ai.starts_at < :to
  AND ai.ends_at > :from
GROUP BY ai.id, ai.event_id, ai.speciality_id, ai.department_id, ai.starts_at, ai.ends_at
SQL,
            [
                'hospital' => $hospitalId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'from' => ParameterType::STRING,
                'to' => ParameterType::STRING,
            ],
        );
        $neighbours = [];
        foreach ($rows as $row) {
            $urgencies = [];
            foreach (explode(',', (string) $row['care_levels']) as $careLevel) {
                foreach (ClosureVolumePopulation::urgenciesForCareLevel($careLevel) as $urgency) {
                    $urgencies[$urgency] = $urgency;
                }
            }
            $start = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['starts_at']));
            $end = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['ends_at']));
            $neighbours[] = new ClosureVolumeInterval(
                (int) $row['id'],
                $hospitalId,
                (int) $row['department_id'],
                array_values($urgencies),
                $start,
                $end,
                'neighbour',
                (int) $row['speciality_id'],
                'department',
                (int) $row['event_id'],
                [[$start, $end]],
            );
        }
        unset($rows);

        return $neighbours;
    }

    /**
     * @param list<ClosureVolumeInterval> $neighbours
     *
     * @return array{0: array<string, array<string, true>>, 1: array<string, array<string, true>>}
     */
    private function blockedMaps(array $neighbours, \DateTimeImmutable $notBefore, \DateTimeImmutable $notAfter): array
    {
        $byDepartment = [];
        $bySpeciality = [];
        foreach ($neighbours as $neighbour) {
            foreach ($neighbour->urgencies as $urgency) {
                $byDepartment[$neighbour->departmentId.'|'.$urgency][] = $neighbour;
                $bySpeciality[$neighbour->specialityId.'|'.$urgency][] = $neighbour;
            }
        }
        $department = [];
        foreach ($byDepartment as $key => $intervals) {
            $department[$key] = $this->calendar->blockedHours($intervals, $notBefore, $notAfter);
        }
        $speciality = [];
        foreach ($bySpeciality as $key => $intervals) {
            $speciality[$key] = $this->calendar->blockedHours($intervals, $notBefore, $notAfter);
        }
        unset($byDepartment, $bySpeciality);

        return [$department, $speciality];
    }

    /**
     * @return array{0: array<string, array<string, int>|int>, 1: array<string, array<string, int>|int>, 2: array<string, true>}
     */
    private function hourlyCounts(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $area = [];
        $hospital = [];
        $covered = [];
        $this->stream(
            <<<'SQL'
SELECT department_id, speciality_id, urgency_code,
       to_char(date_trunc('hour', created_at), 'YYYY-MM-DD HH24:00:00') AS hour_start,
       COUNT(*)::int AS base_count,
       COUNT(*) FILTER (WHERE requires_resus IS TRUE)::int AS resus_count,
       COUNT(*) FILTER (WHERE requires_cathlab IS TRUE)::int AS cathlab_count
FROM allocation_stats_projection
WHERE hospital_id = :hospital
  AND created_at >= :from
  AND created_at < :to
GROUP BY department_id, speciality_id, urgency_code, date_trunc('hour', created_at)
SQL,
            [
                'hospital' => $hospitalId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
            [
                'hospital' => Types::INTEGER,
                'from' => Types::STRING,
                'to' => Types::STRING,
            ],
            function (array $row) use (&$area, &$hospital, &$covered): void {
                $hour = (string) $row['hour_start'];
                $covered[substr($hour, 0, 10)] = true;
                foreach (['base' => 'base_count', 'resus' => 'resus_count', 'cathlab' => 'cathlab_count'] as $stratum => $column) {
                    $count = (int) $row[$column];
                    if (0 === $count) {
                        continue;
                    }
                    $departmentKey = $row['department_id'].'|'.$row['urgency_code'].'|'.$stratum;
                    $specialityKey = 's|'.$row['speciality_id'].'|'.$row['urgency_code'].'|'.$stratum;
                    $hospitalKey = $row['urgency_code'].'|'.$stratum;
                    $area[$departmentKey][$hour] = $count;
                    $area[$specialityKey][$hour] = ($area[$specialityKey][$hour] ?? 0) + $count;
                    $hospital[$hospitalKey][$hour] = ($hospital[$hospitalKey][$hour] ?? 0) + $count;
                }
            },
        );
        $this->forgetDebugQueries();

        return [$area, $hospital, $covered];
    }

    /**
     * @param list<ClosureVolumeInterval>           $anchors
     * @param array<string, array<string, int>|int> $areaCounts
     * @param array<string, array<string, int>|int> $hospitalCounts
     */
    private function fillPartialCounts(int $hospitalId, array $anchors, \DateTimeImmutable $now, array &$areaCounts, array &$hospitalCounts): void
    {
        $ranges = [];
        foreach ($anchors as $anchor) {
            foreach ($this->planner->ranges($anchor, $now) as [, $buckets]) {
                foreach ($buckets as $bucket) {
                    if ('00:00' === $bucket->start->format('i:s') && $bucket->end->getTimestamp() === $bucket->start->getTimestamp() + 3600) {
                        continue;
                    }
                    $key = $bucket->start->format('Y-m-d H:i:s').'|'.$bucket->end->format('Y-m-d H:i:s');
                    $ranges[$key] = [$bucket->start, $bucket->end];
                }
            }
        }
        foreach (array_chunk($ranges, 80, true) as $chunk) {
            $this->queryPartialChunk($hospitalId, $chunk, $areaCounts, $hospitalCounts);
        }
        unset($ranges);
    }

    /**
     * @param array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     * @param array<string, array<string, int>|int>                              $areaCounts
     * @param array<string, array<string, int>|int>                              $hospitalCounts
     */
    private function queryPartialChunk(int $hospitalId, array $ranges, array &$areaCounts, array &$hospitalCounts): void
    {
        $values = [];
        $params = ['hospital' => $hospitalId];
        $types = ['hospital' => ParameterType::INTEGER];
        $index = 0;
        foreach ($ranges as $key => [$start, $end]) {
            $values[] = sprintf('(:k%d, CAST(:s%d AS timestamp), CAST(:e%d AS timestamp))', $index, $index, $index);
            $params['k'.$index] = $key;
            $params['s'.$index] = $start->format('Y-m-d H:i:s');
            $params['e'.$index] = $end->format('Y-m-d H:i:s');
            $types['k'.$index] = ParameterType::STRING;
            $types['s'.$index] = ParameterType::STRING;
            $types['e'.$index] = ParameterType::STRING;
            ++$index;
        }
        if ([] === $values) {
            return;
        }

        $sql = 'SELECT rng.bucket_key, a.department_id, a.speciality_id, a.urgency_code, '
            .'COUNT(*)::int AS base_count, '
            .'COUNT(*) FILTER (WHERE a.requires_resus IS TRUE)::int AS resus_count, '
            .'COUNT(*) FILTER (WHERE a.requires_cathlab IS TRUE)::int AS cathlab_count '
            .'FROM (VALUES '.implode(', ', $values).') AS rng(bucket_key, range_start, range_end) '
            .'JOIN allocation_stats_projection a ON a.hospital_id = :hospital '
            .'AND a.created_at >= rng.range_start AND a.created_at < rng.range_end '
            .'GROUP BY rng.bucket_key, a.department_id, a.speciality_id, a.urgency_code';
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        foreach ($rows as $row) {
            foreach (['base' => 'base_count', 'resus' => 'resus_count', 'cathlab' => 'cathlab_count'] as $stratum => $column) {
                $count = (int) $row[$column];
                if (0 === $count) {
                    continue;
                }
                $bucketKey = (string) $row['bucket_key'];
                $areaCounts[$row['department_id'].'|'.$row['urgency_code'].'|'.$stratum.'|'.$bucketKey] = $count;
                $specialityKey = 's|'.$row['speciality_id'].'|'.$row['urgency_code'].'|'.$stratum.'|'.$bucketKey;
                $existingArea = $areaCounts[$specialityKey] ?? 0;
                $areaCounts[$specialityKey] = (\is_int($existingArea) ? $existingArea : 0) + $count;
                $hospitalKey = $row['urgency_code'].'|'.$stratum.'|'.$bucketKey;
                $existingHospital = $hospitalCounts[$hospitalKey] ?? 0;
                $hospitalCounts[$hospitalKey] = (\is_int($existingHospital) ? $existingHospital : 0) + $count;
            }
        }
        unset($rows);
        $this->forgetDebugQueries();
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private function referenceGroups(ClosureVolumeInterval $anchor, \DateTimeImmutable $now): array
    {
        $groups = [];
        foreach ($this->planner->ranges($anchor, $now) as [, $buckets]) {
            foreach ($buckets as $bucket) {
                $groups[$bucket->weekday.'|'.$bucket->dayTimeBucket] = [$bucket->weekday, $bucket->dayTimeBucket];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, array{0: int, 1: int}>            $groups
     * @param array<string, array<string, int>|int>           $referenceCounts
     * @param array<string, true>                             $coveredDays
     * @param array<string, array<string, true>>              $blockedDepartment
     * @param array<string, array<string, true>>              $blockedSpeciality
     * @param array<string, array<string, ClosureVolumeRate>> $cache
     *
     * @return array<string, ClosureVolumeRate>
     */
    private function referenceRates(
        ClosureVolumeInterval $anchor,
        array $groups,
        array $referenceCounts,
        array $coveredDays,
        array $blockedDepartment,
        array $blockedSpeciality,
        array &$cache,
    ): array {
        $rates = [];
        $cutoffKey = $anchor->startsAt->format('Y-m-d H:i:s');
        foreach ($anchor->urgencies as $urgency) {
            $blockedHours = match ($anchor->scope) {
                'speciality' => $blockedSpeciality[$anchor->specialityId.'|'.$urgency] ?? [],
                'hospital' => [],
                default => $blockedDepartment[$anchor->departmentId.'|'.$urgency] ?? [],
            };
            $prefix = match ($anchor->scope) {
                'speciality' => 's|'.$anchor->specialityId.'|'.$urgency,
                'hospital' => (string) $urgency,
                default => $anchor->departmentId.'|'.$urgency,
            };
            foreach ($groups as [$weekday, $dayTimeBucket]) {
                $cacheKey = $cutoffKey.'|'.$anchor->scope.'|'.$prefix.'|'.$weekday.'|'.$dayTimeBucket;
                if (!isset($cache[$cacheKey])) {
                    $hourlyByStratum = [];
                    foreach (['base', 'resus', 'cathlab'] as $stratum) {
                        $series = $referenceCounts[$prefix.'|'.$stratum] ?? [];
                        $hourlyByStratum[$stratum] = \is_array($series) ? $series : [];
                    }
                    $block = $this->calendar->tallyBlock(
                        $anchor->startsAt,
                        $this->config->referenceWeeks,
                        $weekday,
                        $dayTimeBucket,
                        $hourlyByStratum,
                        $coveredDays,
                        $blockedHours,
                    );
                    $cache[$cacheKey] = [];
                    foreach ($block as $stratum => $hours) {
                        foreach ($hours as $hour => $tallies) {
                            $cache[$cacheKey][$stratum.'|'.$hour] = $this->selector->fromTallies(
                                $tallies[0],
                                $tallies[1],
                                $tallies[2],
                                $tallies[3],
                            );
                        }
                    }
                    unset($block, $hourlyByStratum);
                }
                foreach (['base', 'resus', 'cathlab'] as $stratum) {
                    $hourFrom = ($dayTimeBucket - 1) * 6;
                    for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                        $rates[$prefix.'|'.$stratum.'|'.$weekday.'|'.$hour] = $cache[$cacheKey][$stratum.'|'.$hour];
                    }
                }
            }
        }

        return $rates;
    }

    /**
     * @param array<string, mixed>                 $params
     * @param array<string, string>                $types
     * @param callable(array<string, mixed>): void $onRow
     */
    private function stream(string $sql, array $params, array $types, callable $onRow): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('DECLARE closure_hour_cur NO SCROLL CURSOR FOR '.$sql, $params, $types);
            while (true) {
                $rows = $this->connection->fetchAllAssociative('FETCH 1000 FROM closure_hour_cur');
                if ([] === $rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $onRow($row);
                }
                unset($rows);
            }
            $this->connection->executeStatement('CLOSE closure_hour_cur');
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<ClosureVolumeHourDraft> $pending
     *
     * @return list<ClosureVolumeHourDraft>
     */
    private function flush(array $pending, bool $force): array
    {
        while (\count($pending) >= self::INSERT_BATCH || ($force && [] !== $pending)) {
            $batch = \array_splice($pending, 0, self::INSERT_BATCH);
            $this->insertBatch($batch);
            unset($batch);
        }

        return $pending;
    }

    /**
     * @param list<ClosureVolumeHourDraft> $batch
     */
    private function insertBatch(array $batch): void
    {
        if ([] === $batch) {
            return;
        }
        $values = [];
        $params = [];
        $types = [];
        foreach ($batch as $index => $draft) {
            $values[] = sprintf(
                '(:e%d, :sc%d, :h%d, :sp%d, :d%d, :u%d, :st%d, :bs%d, :be%d, :ic%d, :oa%d, :ea%d, :oh%d, :eh%d, :es%d, :sec%d, :rs%d, :ra%d, :rm%d, :inf%d, :q%d, :rc%d)',
                $index, $index, $index, $index, $index, $index, $index, $index, $index, $index,
                $index, $index, $index, $index, $index, $index, $index, $index, $index, $index, $index, $index,
            );
            $params['e'.$index] = $draft->closureIntervalId;
            $params['sc'.$index] = $draft->scope;
            $params['h'.$index] = $draft->hospitalId;
            $params['sp'.$index] = $draft->specialityId;
            $params['d'.$index] = $draft->departmentId;
            $params['u'.$index] = $draft->urgencyCode;
            $params['st'.$index] = $draft->stratum;
            $params['bs'.$index] = $draft->bucketStart->format('Y-m-d H:i:s');
            $params['be'.$index] = $draft->bucketEnd->format('Y-m-d H:i:s');
            $params['ic'.$index] = $draft->inClosure;
            $params['oa'.$index] = null === $draft->observedArea ? null : sprintf('%.4F', $draft->observedArea);
            $params['ea'.$index] = null === $draft->expectedArea ? null : sprintf('%.4F', $draft->expectedArea);
            $params['oh'.$index] = null === $draft->observedHospital ? null : sprintf('%.4F', $draft->observedHospital);
            $params['eh'.$index] = null === $draft->expectedHospital ? null : sprintf('%.4F', $draft->expectedHospital);
            $params['es'.$index] = $draft->evaluableSeconds;
            $params['sec'.$index] = $draft->bucketSeconds;
            $params['rs'.$index] = $draft->referenceSlotCount;
            $params['ra'.$index] = $draft->referenceAssignmentCount;
            $params['rm'.$index] = $draft->referenceMode;
            $params['inf'.$index] = $draft->influenced;
            $params['q'.$index] = $draft->quality;
            $params['rc'.$index] = $draft->referenceCutoff->format('Y-m-d H:i:s');
            $types['e'.$index] = ParameterType::INTEGER;
            $types['sc'.$index] = ParameterType::STRING;
            $types['h'.$index] = ParameterType::INTEGER;
            $types['sp'.$index] = ParameterType::INTEGER;
            $types['d'.$index] = ParameterType::INTEGER;
            $types['u'.$index] = ParameterType::INTEGER;
            $types['st'.$index] = ParameterType::STRING;
            $types['bs'.$index] = ParameterType::STRING;
            $types['be'.$index] = ParameterType::STRING;
            $types['ic'.$index] = ParameterType::BOOLEAN;
            $types['oa'.$index] = null === $draft->observedArea ? ParameterType::NULL : ParameterType::STRING;
            $types['ea'.$index] = null === $draft->expectedArea ? ParameterType::NULL : ParameterType::STRING;
            $types['oh'.$index] = null === $draft->observedHospital ? ParameterType::NULL : ParameterType::STRING;
            $types['eh'.$index] = null === $draft->expectedHospital ? ParameterType::NULL : ParameterType::STRING;
            $types['es'.$index] = ParameterType::INTEGER;
            $types['sec'.$index] = ParameterType::INTEGER;
            $types['rs'.$index] = ParameterType::INTEGER;
            $types['ra'.$index] = ParameterType::INTEGER;
            $types['rm'.$index] = ParameterType::STRING;
            $types['inf'.$index] = ParameterType::BOOLEAN;
            $types['q'.$index] = ParameterType::STRING;
            $types['rc'.$index] = ParameterType::STRING;
        }
        /** @psalm-suppress InvalidArgument Doctrine DBAL parameter types include SQL NULL */
        $this->connection->executeStatement(
            'INSERT INTO closure_volume_hour_build ('
            .'event_id, scope, hospital_id, speciality_id, department_id, urgency_code, stratum, '
            .'bucket_start, bucket_end, in_closure, observed_area, expected_area, '
            .'observed_hospital, expected_hospital, evaluable_seconds, bucket_seconds, '
            .'reference_slot_count, reference_assignment_count, reference_mode, influenced, quality, reference_cutoff'
            .') VALUES '.implode(', ', $values),
            $params,
            $types,
        );
        $this->forgetDebugQueries();
    }

    private function createBuildTable(): void
    {
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE closure_volume_hour_build (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
    event_id BIGINT NOT NULL,
    scope VARCHAR(16) NOT NULL,
    hospital_id INT NOT NULL,
    speciality_id INT NOT NULL,
    department_id INT NOT NULL,
    urgency_code SMALLINT NOT NULL,
    stratum VARCHAR(16) NOT NULL,
    bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    bucket_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    in_closure BOOLEAN NOT NULL,
    observed_area NUMERIC(14, 4) DEFAULT NULL,
    expected_area NUMERIC(14, 4) DEFAULT NULL,
    observed_hospital NUMERIC(14, 4) DEFAULT NULL,
    expected_hospital NUMERIC(14, 4) DEFAULT NULL,
    evaluable_seconds INT NOT NULL,
    bucket_seconds INT NOT NULL,
    reference_slot_count INT NOT NULL,
    reference_assignment_count INT NOT NULL,
    reference_mode VARCHAR(32) NOT NULL,
    influenced BOOLEAN NOT NULL,
    quality VARCHAR(32) NOT NULL,
    reference_cutoff TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_closure_volume_hour_build_grain ON closure_volume_hour_build (event_id, scope, speciality_id, department_id, urgency_code, stratum, bucket_start)');
        $this->connection->executeStatement('CREATE INDEX idx_closure_volume_hour_build_population ON closure_volume_hour_build (hospital_id, scope, speciality_id, department_id, urgency_code, stratum, bucket_start)');
        $this->connection->executeStatement('CREATE INDEX idx_closure_volume_hour_build_event ON closure_volume_hour_build (event_id, scope, stratum, in_closure, bucket_start)');
    }

    private function swap(): void
    {
        $this->connection->transactional(function (): void {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour');
            $this->connection->executeStatement('ALTER TABLE closure_volume_hour_build RENAME TO closure_volume_hour');
            $this->connection->executeStatement('ALTER INDEX uniq_closure_volume_hour_build_grain RENAME TO uniq_closure_volume_hour_grain');
            $this->connection->executeStatement('ALTER INDEX idx_closure_volume_hour_build_population RENAME TO idx_closure_volume_hour_population');
            $this->connection->executeStatement('ALTER INDEX idx_closure_volume_hour_build_event RENAME TO idx_closure_volume_hour_event');
        });
    }

    /**
     * @return list<int>
     */
    private function hospitalIds(): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn('SELECT DISTINCT hospital_id FROM closure_event ORDER BY hospital_id ASC');

        return array_map(static fn (int|string $id): int => (int) $id, $rows);
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function countEvents(array $hospitalIds): int
    {
        if ([] === $hospitalIds) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM closure_event WHERE hospital_id IN (:ids)',
            ['ids' => $hospitalIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    private function sample(string $phase, float $seconds = 0.0, int $retained = 0): void
    {
        $this->maxPartitionBytes = max($this->maxPartitionBytes, $retained);
        $this->logger->info('closure_volume.partition', [
            'phase' => $phase,
            'seconds' => round($seconds, 3),
            'bytes' => $retained,
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ]);
    }

    private function forgetDebugQueries(): void
    {
        $this->debugDataHolder?->reset();
    }

    private function silenceDoctrineLog(): bool
    {
        if (!$this->doctrineLogger instanceof Logger) {
            return false;
        }
        $this->doctrineLogger->pushHandler(new NullHandler());

        return true;
    }

    private function restoreDoctrineLog(bool $silenced): void
    {
        if ($silenced && $this->doctrineLogger instanceof Logger) {
            $this->doctrineLogger->popHandler();
        }
    }
}
