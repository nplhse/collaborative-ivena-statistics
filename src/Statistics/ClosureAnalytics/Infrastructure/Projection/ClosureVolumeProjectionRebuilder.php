<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeAnchor;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeAnchorBuilder;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourPlanner;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeInterval;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeMetricKind;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumePopulation;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeProvisionalReason;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRate;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceCalendar;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSelector;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildProfile;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildTelemetry;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureCoverageQuery;
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

    private ClosureVolumeReferenceWindow $window;

    public readonly ClosureRebuildProfile $profile;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClosureVolumeAnchorBuilder $anchorBuilder,
        private readonly ClosureVolumeReferenceConfig $config,
        private readonly ClosureVolumeReferenceCalendar $calendar,
        private readonly ClosureVolumeHourPlanner $planner,
        private readonly ClosureVolumeReferenceSelector $selector,
        private readonly ClosureCoverageQuery $coverage,
        private readonly ClosureRebuildTelemetry $telemetry,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly ?DebugDataHolder $debugDataHolder = null,
        #[Autowire(service: 'monolog.logger.doctrine')]
        private readonly ?LoggerInterface $doctrineLogger = null,
    ) {
        $this->profile = new ClosureRebuildProfile();
    }

    /**
     * @param callable(int $processed, int $total, int $pass): void|null           $onProgress      pass 2 rebuilds hospitals with late requests
     * @param list<int>|null                                                       $hospitalIds     null replaces the whole projection
     * @param callable(list<int>|null): array{full: bool, refresh: list<int>}|null $expandHospitals
     */
    public function rebuild(
        ?callable $onProgress = null,
        ?array $hospitalIds = null,
        ?callable $expandHospitals = null,
        ?ClosureVolumeReferenceWindow $window = null,
    ): int {
        $this->window = $window ?? $this->config->window;
        $this->telemetry->referenceWindow($this->window);
        $this->profile->reset();
        $this->maxPartitionBytes = 0;
        $this->partitionSamples = [];
        $silenced = $this->silenceDoctrineLog();
        $full = null === $hospitalIds;

        try {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour_build');
            $this->createBuildTable();
            $ids = $hospitalIds ?? $this->hospitalIds();
            $pending = [];
            $pass = 1;
            $done = 0;
            $total = $this->countEvents($ids);
            $this->profile->countQueries('setup', null === $hospitalIds ? 7 : 6);
            $report = null === $onProgress ? null : static function (int $processed, int $events) use ($onProgress, &$pass): void {
                $onProgress($processed, $events, $pass);
            };
            if (null !== $report) {
                $report(0, $total);
            }
            $this->telemetry->startPass($pass, $ids, $total);
            foreach ($ids as $hospitalId) {
                $before = memory_get_usage(false);
                $pending = $this->rebuildHospital($hospitalId, $pending, $done, $total, $report);
                $this->forgetDebugQueries();
                gc_collect_cycles();
                $this->sample('hospital '.$hospitalId, 0.0, max(0, memory_get_usage(false) - $before));
            }
            $this->flush($pending, true);
            unset($pending);
            $this->telemetry->finishSpan();
            if (null !== $expandHospitals) {
                $this->telemetry->startSpan('closure.rebuild.late_claim');
                $decision = $expandHospitals($full ? null : $ids);
                $this->telemetry->finishSpan(['full' => $decision['full'], 'hospital_ids' => $decision['refresh']]);
                $refresh = $decision['refresh'];
                $missing = $decision['full']
                    ? array_values(array_diff($this->hospitalIds(), array_values(array_unique([...$ids, ...$refresh]))))
                    : [];
                $late = [...$refresh, ...$missing];
                if ([] !== $late) {
                    // Requests claimed after the first pass get a pass of their own, so the progress does not stall at 100 %.
                    $this->logger->info('closure_volume.late_requests', ['hospital_ids' => $late, 'full' => $decision['full']]);
                    ++$pass;
                    $done = 0;
                    $total = $this->countEvents($late);
                    if (null !== $report) {
                        $report(0, $total);
                    }
                    $this->telemetry->startPass($pass, $late, $total);
                }
                if ([] !== $refresh) {
                    /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
                    $this->connection->executeStatement(
                        'DELETE FROM closure_volume_hour_build WHERE hospital_id IN (:ids)',
                        ['ids' => $refresh],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }
                $pending = [];
                foreach ($late as $hospitalId) {
                    $pending = $this->rebuildHospital($hospitalId, $pending, $done, $total, $report);
                }
                if ($decision['full']) {
                    $full = true;
                    $ids = $this->hospitalIds();
                } elseif ([] !== $refresh) {
                    $ids = array_values(array_unique([...$ids, ...$refresh]));
                }
                $this->flush($pending, true);
                if ([] !== $late) {
                    $this->telemetry->finishSpan();
                }
                unset($pending, $refresh, $missing, $late, $decision);
            }
            if ($this->beforePublishHook instanceof \Closure) {
                ($this->beforePublishHook)();
            }
            if (null !== $report) {
                $report($total, $total);
            }
            $publishStartedAt = microtime(true);
            $this->telemetry->startSpan('closure.rebuild.publish', ['full' => $full, 'hospital_ids' => $ids]);
            $this->profile->time('publish', fn () => $this->publish($full, $ids), $full ? 5 : 3);
            $this->telemetry->finishSpan();
            $this->telemetry->published($full, $ids, microtime(true) - $publishStartedAt);
            $this->telemetry->countQueries($this->profile->queries());
        } catch (\Throwable $exception) {
            $this->dropBuildTableAfterFailure($exception);
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
    event_id, scope, hospital_id, speciality_id, department_id, metric_kind,
    bucket_start, bucket_end, in_closure, observed_area, expected_area, observed_hospital,
    expected_hospital, evaluable_seconds, bucket_seconds, reference_slot_count, blocked_slot_count,
    reference_assignment_count, reference_mode, influenced, quality, closure_relevance,
    closed_department_count, provisional, provisional_reason, reference_cutoff
)
SELECT event_id, scope, hospital_id, speciality_id, department_id, metric_kind,
    bucket_start, bucket_end, in_closure, observed_area, expected_area, observed_hospital,
    expected_hospital, evaluable_seconds, bucket_seconds, reference_slot_count, blocked_slot_count,
    reference_assignment_count, reference_mode, influenced, quality, closure_relevance,
    closed_department_count, provisional, provisional_reason, reference_cutoff
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

        $this->telemetry->startSpan('closure.rebuild.hospital', ['hospital_id' => $hospitalId]);
        $this->profile->countQueries('setup', 3);
        $coverage = $this->coverage->allocations($hospitalId);
        if (!$coverage instanceof ClosureCoverage) {
            $this->logger->info('closure_volume.coverage_fallback', ['hospital_id' => $hospitalId]);
        }
        $closureCoverage = $this->coverage->closures($hospitalId);
        $weekStart = new \DateTimeImmutable($bounds['min_start'])->setTime(0, 0);
        $last = new \DateTimeImmutable((string) $bounds['max_start'])->modify('+1 day');
        while ($weekStart < $last) {
            $weekEnd = $weekStart->modify('+7 days');
            $pending = $this->rebuildPartition($hospitalId, $weekStart, $weekEnd, $coverage, $closureCoverage, $pending, $done, $total, $onProgress);
            $weekStart = $weekEnd;
        }
        $this->telemetry->finishSpan();

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
        ?ClosureCoverage $coverage,
        ?ClosureCoverage $closureCoverage,
        array $pending,
        int &$done,
        int $total,
        ?callable $onProgress,
    ): array {
        $started = microtime(true);
        $before = memory_get_usage(false);
        $rows = $this->profile->time('anchors', fn (): array => $this->anchorRows($hospitalId, $weekStart, $weekEnd), 1);
        if ([] === $rows) {
            return $pending;
        }
        // Partition spans carry ids and counts only, not the week, so closure periods stay local.
        $this->telemetry->startSpan('closure.rebuild.partition', ['hospital_id' => $hospitalId]);
        $this->telemetry->countPartition();

        $now = ClosureVolumeClock::now();
        /** @var array<int, \DateTimeImmutable> $eventEnds */
        $eventEnds = [];
        $from = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $rows[0]['starts_at']));
        $to = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $rows[0]['ends_at']));
        foreach ($rows as $row) {
            $start = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['starts_at']));
            $end = ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['ends_at']));
            $from = $start < $from ? $start : $from;
            $to = $end > $to ? $end : $to;
            $eventId = (int) $row['event_id'];
            if (!isset($eventEnds[$eventId]) || $end > $eventEnds[$eventId]) {
                $eventEnds[$eventId] = $end;
            }
        }
        [$windowStart, $windowEnd] = $this->window->loadRange($from, $to);
        $windowStart = $windowStart->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        $windowEnd = $windowEnd->modify(sprintf('+%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        if ($windowEnd > $now) {
            $windowEnd = $now;
        }
        if ($windowStart >= $windowEnd) {
            $windowEnd = $windowStart->modify('+1 second');
        }

        $neighbours = $this->profile->time('neighbours', fn (): array => $this->neighbours($hospitalId, $windowStart, $windowEnd), 1);
        [$counts, $coveredDays, $departmentsBySpeciality] = $this->profile->time('hourly_counts', fn (): array => $this->hourlyCounts($hospitalId, $windowStart, $windowEnd));
        if ($coverage instanceof ClosureCoverage) {
            // Import export periods are authoritative; a covered day without allocations is a real zero.
            $coveredDays = $coverage->coveredDays($windowStart->setTime(0, 0), $windowEnd);
        }
        $anchors = $this->profile->time('anchors', fn (): array => $this->anchorBuilder->metricAnchors($rows, $hospitalId, $departmentsBySpeciality));
        unset($rows, $departmentsBySpeciality);
        $this->profile->time('partial_counts', function () use ($hospitalId, $anchors, $now, &$counts): void {
            $this->fillPartialCounts($hospitalId, $anchors, $now, $counts);
        });
        $blocked = $this->profile->time('blocking', fn (): array => $this->blockedMaps($neighbours, $windowStart, $windowEnd));
        $provisional = [];
        foreach ($eventEnds as $eventId => $end) {
            $provisional[$eventId] = ClosureVolumeProvisionalReason::forEvent($end, $this->window, $coverage, $closureCoverage);
        }
        /** @var array<string, array<int, ClosureVolumeRate>> $rateCache */
        $rateCache = [];

        foreach ($anchors as $anchor) {
            $groups = $this->referenceGroups($anchor, $now);
            $series = ClosureVolumeHourPlanner::series($anchor->scope, $anchor->specialityId, $anchor->departmentId, $anchor->metric);
            $hospitalSeries = ClosureVolumeHourPlanner::hospitalSeries($anchor->metric);
            [$areaRates, $hospitalRates] = $this->profile->time('reference_rates', fn (): array => [
                $this->referenceRates($anchor, $series, $groups, $counts, $coveredDays, 'hospital' === $anchor->scope ? [] : ($blocked[$series] ?? []), $rateCache),
                $this->referenceRates($anchor, $hospitalSeries, $groups, $counts, $coveredDays, [], $rateCache),
            ]);
            $pending = array_merge($pending, $this->profile->time('plan', fn (): array => $this->planner->plan(
                $anchor,
                $now,
                $counts,
                $areaRates,
                $hospitalRates,
                $coveredDays,
                $neighbours,
                $provisional[$anchor->eventId] ?? null,
            )));
            if (\count($pending) >= self::INSERT_BATCH) {
                $pending = $this->flush($pending, false);
            }
        }
        $events = \count($eventEnds);
        $done += $events;
        if (null !== $onProgress) {
            $onProgress(min($done, $total), $total);
        }

        unset($anchors, $neighbours, $counts, $coveredDays, $blocked, $rateCache, $eventEnds, $provisional);
        gc_collect_cycles();
        $retained = max(0, memory_get_usage(false) - $before);
        $this->partitionSamples[] = $retained;
        $this->telemetry->finishSpan(['events' => $events, 'retained_bytes' => $retained]);
        $this->sample($hospitalId.' '.$weekStart->format('Y-m-d'), microtime(true) - $started, $retained);

        return $pending;
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function anchorRows(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
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

        return $rows;
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
            ksort($urgencies);
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
     * Reference hours blocked per series (ADR 015). A department blocks an urgency metric while an
     * interval opens that urgency, and a resource metric while SK1–SK3 are closed in the same hour,
     * possibly by several intervals. A speciality blocks a metric where any of its departments does.
     *
     * @param list<ClosureVolumeInterval> $neighbours
     *
     * @return array<string, array<string, true>> series => Y-m-d H:00:00 => true
     */
    private function blockedMaps(array $neighbours, \DateTimeImmutable $notBefore, \DateTimeImmutable $notAfter): array
    {
        /** @var array<int, array<int, list<ClosureVolumeInterval>>> $byDepartment */
        $byDepartment = [];
        /** @var array<int, array<int, true>> $specialities department => speciality => true */
        $specialities = [];
        foreach ($neighbours as $neighbour) {
            $specialities[$neighbour->departmentId][$neighbour->specialityId] = true;
            foreach ($neighbour->urgencies as $urgency) {
                $byDepartment[$neighbour->departmentId][$urgency][] = $neighbour;
            }
        }
        $blocked = [];
        foreach ($byDepartment as $departmentId => $byUrgency) {
            $hours = [];
            foreach ($byUrgency as $urgency => $intervals) {
                $hours[$urgency] = $this->calendar->blockedHours($intervals, $notBefore, $notAfter);
            }
            foreach (ClosureVolumeMetricKind::all() as $metric) {
                $metricHours = null;
                foreach ($metric->bindingUrgencies() as $urgency) {
                    $urgencyHours = $hours[$urgency] ?? [];
                    $metricHours = null === $metricHours ? $urgencyHours : array_intersect_key($metricHours, $urgencyHours);
                }
                if (null === $metricHours || [] === $metricHours) {
                    continue;
                }
                $blocked['d|'.$departmentId.'|'.$metric->value] = $metricHours;
                foreach (array_keys($specialities[$departmentId] ?? []) as $specialityId) {
                    $key = 's|'.$specialityId.'|'.$metric->value;
                    $blocked[$key] = ($blocked[$key] ?? []) + $metricHours;
                }
            }
        }
        unset($byDepartment, $specialities);

        return $blocked;
    }

    /**
     * Hourly counts per series, the days that had allocations and the known departments per speciality.
     *
     * @return array{0: array<string, array<string, int>|int>, 1: array<string, true>, 2: array<int, list<int>>}
     */
    private function hourlyCounts(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $counts = [];
        $covered = [];
        $departments = [];
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
            function (array $row) use (&$counts, &$covered, &$departments): void {
                $hour = (string) $row['hour_start'];
                $covered[substr($hour, 0, 10)] = true;
                $departments[(int) $row['speciality_id']][(int) $row['department_id']] = true;
                foreach ($this->metricCounts($row) as $metric => $count) {
                    foreach ($this->seriesKeys((int) $row['department_id'], (int) $row['speciality_id'], $metric) as $series) {
                        $counts[$series][$hour] = ($counts[$series][$hour] ?? 0) + $count;
                    }
                }
            },
        );
        $this->forgetDebugQueries();

        return [$counts, $covered, array_map(array_keys(...), $departments)];
    }

    /**
     * @param array<string, mixed> $row urgency_code, base_count, resus_count, cathlab_count
     *
     * @return array<string, int> metric => count, zero counts left out
     */
    private function metricCounts(array $row): array
    {
        $counts = [];
        $urgency = is_numeric($row['urgency_code'] ?? null) ? (int) $row['urgency_code'] : 0;
        if ($urgency >= 1 && $urgency <= 3 && (int) $row['base_count'] > 0) {
            $counts[ClosureVolumeMetricKind::forUrgency($urgency)->value] = (int) $row['base_count'];
        }
        if ((int) $row['resus_count'] > 0) {
            $counts[ClosureVolumeMetricKind::Resus->value] = (int) $row['resus_count'];
        }
        if ((int) $row['cathlab_count'] > 0) {
            $counts[ClosureVolumeMetricKind::Cathlab->value] = (int) $row['cathlab_count'];
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function seriesKeys(int $departmentId, int $specialityId, string $metric): array
    {
        return ['d|'.$departmentId.'|'.$metric, 's|'.$specialityId.'|'.$metric, 'h|'.$metric];
    }

    /**
     * @param list<ClosureVolumeAnchor>             $anchors
     * @param array<string, array<string, int>|int> $counts
     */
    private function fillPartialCounts(int $hospitalId, array $anchors, \DateTimeImmutable $now, array &$counts): void
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
            $this->queryPartialChunk($hospitalId, $chunk, $counts);
        }
        unset($ranges);
    }

    /**
     * @param array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     * @param array<string, array<string, int>|int>                              $counts
     */
    private function queryPartialChunk(int $hospitalId, array $ranges, array &$counts): void
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
        $this->profile->countQueries('partial_counts');
        foreach ($rows as $row) {
            $bucketKey = (string) $row['bucket_key'];
            foreach ($this->metricCounts($row) as $metric => $count) {
                foreach ($this->seriesKeys((int) $row['department_id'], (int) $row['speciality_id'], $metric) as $series) {
                    $existing = $counts[$series.'|'.$bucketKey] ?? 0;
                    $counts[$series.'|'.$bucketKey] = (\is_int($existing) ? $existing : 0) + $count;
                }
            }
        }
        unset($rows);
        $this->forgetDebugQueries();
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private function referenceGroups(ClosureVolumeAnchor $anchor, \DateTimeImmutable $now): array
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
     * @param array<string, array{0: int, 1: int}>         $groups
     * @param array<string, array<string, int>|int>        $counts
     * @param array<string, true>                          $coveredDays
     * @param array<string, true>                          $blockedHours
     * @param array<string, array<int, ClosureVolumeRate>> $cache
     *
     * @return array<string, ClosureVolumeRate> key "{weekday}|{hour}"
     */
    private function referenceRates(
        ClosureVolumeAnchor $anchor,
        string $series,
        array $groups,
        array $counts,
        array $coveredDays,
        array $blockedHours,
        array &$cache,
    ): array {
        $rates = [];
        $ranges = $this->window->ranges($anchor->startsAt, $anchor->endsAt);
        $windowKey = $anchor->startsAt->format('Y-m-d H:i:s').'|'.$anchor->endsAt->format('Y-m-d H:i:s');
        $hourly = $counts[$series] ?? [];
        $hourly = \is_array($hourly) ? $hourly : [];
        foreach ($groups as [$weekday, $dayTimeBucket]) {
            $cacheKey = $windowKey.'|'.$series.'|'.$weekday.'|'.$dayTimeBucket;
            if (!isset($cache[$cacheKey])) {
                $tallies = $this->calendar->tallyWindow($ranges, $weekday, $dayTimeBucket, ['m' => $hourly], $coveredDays, ['m' => $blockedHours]);
                $cache[$cacheKey] = [];
                foreach ($tallies['m'] as $hour => $tally) {
                    $cache[$cacheKey][$hour] = $this->selector->fromTally($tally);
                }
                unset($tallies);
            }
            foreach ($cache[$cacheKey] as $hour => $rate) {
                $rates[$weekday.'|'.$hour] = $rate;
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
            $this->profile->countQueries('hourly_counts', 4);
            while (true) {
                $rows = $this->connection->fetchAllAssociative('FETCH 1000 FROM closure_hour_cur');
                $this->profile->countQueries('hourly_counts');
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
        $this->telemetry->countHoursWritten(\count($batch));
        $values = [];
        $params = [];
        $types = [];
        foreach ($batch as $index => $draft) {
            $row = [
                'e' => [$draft->eventId, ParameterType::INTEGER],
                'sc' => [$draft->scope, ParameterType::STRING],
                'h' => [$draft->hospitalId, ParameterType::INTEGER],
                'sp' => [$draft->specialityId, ParameterType::INTEGER],
                'd' => [$draft->departmentId, ParameterType::INTEGER],
                'mk' => [$draft->metric->value, ParameterType::STRING],
                'bs' => [$draft->bucketStart->format('Y-m-d H:i:s'), ParameterType::STRING],
                'be' => [$draft->bucketEnd->format('Y-m-d H:i:s'), ParameterType::STRING],
                'ic' => [$draft->inClosure, ParameterType::BOOLEAN],
                'oa' => $this->numeric($draft->observedArea),
                'ea' => $this->numeric($draft->expectedArea),
                'oh' => $this->numeric($draft->observedHospital),
                'eh' => $this->numeric($draft->expectedHospital),
                'es' => [$draft->evaluableSeconds, ParameterType::INTEGER],
                'sec' => [$draft->bucketSeconds, ParameterType::INTEGER],
                'rs' => [$draft->referenceSlotCount, ParameterType::INTEGER],
                'bl' => [$draft->blockedSlotCount, ParameterType::INTEGER],
                'ra' => [$draft->referenceAssignmentCount, ParameterType::INTEGER],
                'rm' => [$draft->referenceMode, ParameterType::STRING],
                'inf' => [$draft->influenced, ParameterType::BOOLEAN],
                'q' => [$draft->quality, ParameterType::STRING],
                'rel' => [$draft->relevance->value, ParameterType::STRING],
                'cd' => [$draft->closedDepartmentCount, ParameterType::INTEGER],
                'pv' => [$draft->provisional, ParameterType::BOOLEAN],
                'pr' => null === $draft->provisionalReason ? [null, ParameterType::NULL] : [$draft->provisionalReason->value, ParameterType::STRING],
                'rc' => [$draft->referenceCutoff->format('Y-m-d H:i:s'), ParameterType::STRING],
            ];
            $placeholders = [];
            foreach ($row as $name => [$value, $type]) {
                $placeholders[] = ':'.$name.$index;
                $params[$name.$index] = $value;
                $types[$name.$index] = $type;
            }
            $values[] = '('.implode(', ', $placeholders).')';
        }
        $started = microtime(true);
        /** @psalm-suppress InvalidArgument Doctrine DBAL parameter types include SQL NULL */
        $this->connection->executeStatement(
            'INSERT INTO closure_volume_hour_build ('
            .'event_id, scope, hospital_id, speciality_id, department_id, metric_kind, '
            .'bucket_start, bucket_end, in_closure, observed_area, expected_area, '
            .'observed_hospital, expected_hospital, evaluable_seconds, bucket_seconds, '
            .'reference_slot_count, blocked_slot_count, reference_assignment_count, reference_mode, influenced, quality, '
            .'closure_relevance, closed_department_count, provisional, provisional_reason, reference_cutoff'
            .') VALUES '.implode(', ', $values),
            $params,
            $types,
        );
        $this->profile->record('insert', microtime(true) - $started, 1);
        $this->forgetDebugQueries();
    }

    /**
     * @return array{0: string|null, 1: ParameterType}
     */
    private function numeric(?float $value): array
    {
        return null === $value ? [null, ParameterType::NULL] : [sprintf('%.4F', $value), ParameterType::STRING];
    }

    /**
     * Best-effort clean-up: inside an aborted outer transaction the DROP fails as well,
     * and that failure must not hide the exception that stopped the rebuild.
     */
    private function dropBuildTableAfterFailure(\Throwable $cause): void
    {
        try {
            $this->connection->executeStatement('DROP TABLE IF EXISTS closure_volume_hour_build');
        } catch (\Throwable $cleanup) {
            $this->logger->warning('closure_volume.build_table_cleanup_failed', [
                'cause' => $cause->getMessage(),
                'exception' => $cleanup,
            ]);
        }
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
    metric_kind VARCHAR(16) NOT NULL,
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
    blocked_slot_count INT NOT NULL,
    reference_assignment_count INT NOT NULL,
    reference_mode VARCHAR(32) NOT NULL,
    influenced BOOLEAN NOT NULL,
    quality VARCHAR(32) NOT NULL,
    closure_relevance VARCHAR(16) NOT NULL,
    closed_department_count INT NOT NULL,
    provisional BOOLEAN NOT NULL,
    provisional_reason VARCHAR(32) DEFAULT NULL,
    reference_cutoff TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_closure_volume_hour_build_grain ON closure_volume_hour_build (event_id, scope, speciality_id, department_id, metric_kind, bucket_start)');
        $this->connection->executeStatement('CREATE INDEX idx_closure_volume_hour_build_population ON closure_volume_hour_build (hospital_id, scope, speciality_id, department_id, metric_kind, bucket_start)');
        $this->connection->executeStatement('CREATE INDEX idx_closure_volume_hour_build_event ON closure_volume_hour_build (event_id, scope, metric_kind, in_closure, bucket_start)');
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
