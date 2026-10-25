<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Builds volume projection anchors from persisted closure event rows.
 */
final readonly class ClosureVolumeAnchorBuilder
{
    /**
     * One department, speciality and hospital anchor per event and closed urgency.
     *
     * @param list<array<string, int|string>> $rows event_id, speciality_id, department_id, starts_at, ends_at, care_level
     *
     * @return list<ClosureVolumeInterval>
     */
    public function fromRows(array $rows, int $hospitalId): array
    {
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

        return $anchors;
    }

    /**
     * All five metrics for every department, speciality and the hospital an event touches (ADR 015).
     *
     * A department binds an urgency metric while an interval opens that urgency, and a resource
     * metric while SK1–SK3 are closed at the same time, possibly by several intervals. A speciality
     * or the hospital binds a metric where any of its departments does; relevance compares the
     * closed departments with all known departments of the population.
     *
     * @param list<array<string, int|string>> $rows                    event_id, speciality_id, department_id, starts_at, ends_at, care_level
     * @param array<int, list<int>>           $departmentsBySpeciality known departments of the hospital per speciality; closed ones are added
     *
     * @return list<ClosureVolumeAnchor>
     */
    public function metricAnchors(array $rows, int $hospitalId, array $departmentsBySpeciality): array
    {
        /** @var array<int, array<int, array{speciality: int, all: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>, urgency: array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>}>> $events */
        $events = [];
        foreach ($rows as $row) {
            $eventId = (int) $row['event_id'];
            $departmentId = (int) $row['department_id'];
            $segment = [
                ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['starts_at'])),
                ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['ends_at'])),
            ];
            $events[$eventId][$departmentId] ??= ['speciality' => (int) $row['speciality_id'], 'all' => [], 'urgency' => []];
            $events[$eventId][$departmentId]['all'][] = $segment;
            foreach (ClosureVolumePopulation::urgenciesForCareLevel((string) $row['care_level']) as $urgency) {
                $events[$eventId][$departmentId]['urgency'][$urgency][] = $segment;
            }
        }

        $known = [];
        foreach ($departmentsBySpeciality as $specialityId => $departmentIds) {
            foreach ($departmentIds as $departmentId) {
                $known[$specialityId][$departmentId] = true;
            }
        }

        $anchors = [];
        foreach ($events as $eventId => $departments) {
            $specialityUniverse = $known;
            $hospitalUniverse = [];
            foreach ($departments as $departmentId => $department) {
                $specialityUniverse[$department['speciality']][$departmentId] = true;
            }
            foreach ($specialityUniverse as $members) {
                $hospitalUniverse += $members;
            }

            /** @var array<int, array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>> $bound department => metric => segments */
            $bound = [];
            foreach ($departments as $departmentId => $department) {
                foreach (ClosureVolumeMetricKind::all() as $metric) {
                    $lists = [];
                    foreach ($metric->bindingUrgencies() as $urgency) {
                        $lists[] = $department['urgency'][$urgency] ?? [];
                    }
                    $bound[$departmentId][$metric->value] = ClosureVolumeSegments::intersect($lists);
                }
            }

            foreach ($departments as $departmentId => $department) {
                $all = ClosureVolumeSegments::union($department['all']);
                foreach (ClosureVolumeMetricKind::all() as $metric) {
                    $segments = $bound[$departmentId][$metric->value];
                    $anchors[] = new ClosureVolumeAnchor(
                        $eventId,
                        $hospitalId,
                        'department',
                        $department['speciality'],
                        $departmentId,
                        $metric,
                        [] === $segments ? ClosureVolumeRelevance::None : ClosureVolumeRelevance::Closed,
                        [] === $segments ? 0 : 1,
                        [] === $segments ? $all : $segments,
                    );
                }
            }

            $bySpeciality = [];
            foreach ($departments as $departmentId => $department) {
                $bySpeciality[$department['speciality']][] = $departmentId;
            }
            foreach ($bySpeciality as $specialityId => $departmentIds) {
                $anchors = [...$anchors, ...$this->aggregateAnchors($eventId, $hospitalId, 'speciality', $specialityId, $departmentIds, \count($specialityUniverse[$specialityId] ?? []), $departments, $bound)];
            }
            $anchors = [...$anchors, ...$this->aggregateAnchors($eventId, $hospitalId, 'hospital', 0, array_keys($departments), \count($hospitalUniverse), $departments, $bound)];
        }

        return $anchors;
    }

    /**
     * @param list<int>                                                                                                                                                                          $departmentIds closed departments of the population in this event
     * @param array<int, array{speciality: int, all: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>, urgency: array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>}> $departments
     * @param array<int, array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>>                                                                                               $bound
     *
     * @return list<ClosureVolumeAnchor>
     */
    private function aggregateAnchors(
        int $eventId,
        int $hospitalId,
        string $scope,
        int $specialityId,
        array $departmentIds,
        int $universe,
        array $departments,
        array $bound,
    ): array {
        $all = [];
        foreach ($departmentIds as $departmentId) {
            $all = [...$all, ...$departments[$departmentId]['all']];
        }
        $all = ClosureVolumeSegments::union($all);
        $anchors = [];
        foreach (ClosureVolumeMetricKind::all() as $metric) {
            $segments = [];
            $closed = 0;
            foreach ($departmentIds as $departmentId) {
                if ([] !== $bound[$departmentId][$metric->value]) {
                    ++$closed;
                    $segments = [...$segments, ...$bound[$departmentId][$metric->value]];
                }
            }
            $anchors[] = new ClosureVolumeAnchor(
                $eventId,
                $hospitalId,
                $scope,
                $specialityId,
                0,
                $metric,
                ClosureVolumeRelevance::forCount($closed, max($universe, $closed)),
                $closed,
                0 === $closed ? $all : ClosureVolumeSegments::union($segments),
            );
        }

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
}
