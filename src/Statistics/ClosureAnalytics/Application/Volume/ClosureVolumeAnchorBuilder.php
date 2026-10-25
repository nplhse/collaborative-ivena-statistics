<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Builds volume projection anchors from persisted closure event rows.
 */
final readonly class ClosureVolumeAnchorBuilder
{
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
        // A department is a population per speciality: the same department can close under two specialities.
        /** @var array<int, array<string, array{speciality: int, department: int, all: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>, urgency: array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>}>> $events */
        $events = [];
        foreach ($rows as $row) {
            $eventId = (int) $row['event_id'];
            $specialityId = (int) $row['speciality_id'];
            $departmentId = (int) $row['department_id'];
            $key = $specialityId.'|'.$departmentId;
            $segment = [
                ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['starts_at'])),
                ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['ends_at'])),
            ];
            $events[$eventId][$key] ??= ['speciality' => $specialityId, 'department' => $departmentId, 'all' => [], 'urgency' => []];
            $events[$eventId][$key]['all'][] = $segment;
            foreach (ClosureVolumePopulation::urgenciesForCareLevel((string) $row['care_level']) as $urgency) {
                $events[$eventId][$key]['urgency'][$urgency][] = $segment;
            }
        }

        $known = [];
        foreach ($departmentsBySpeciality as $specialityId => $departmentIds) {
            foreach ($departmentIds as $departmentId) {
                $known[$specialityId][$departmentId] = true;
            }
        }

        $anchors = [];
        foreach ($events as $eventId => $members) {
            $specialityUniverse = $known;
            foreach ($members as $member) {
                $specialityUniverse[$member['speciality']][$member['department']] = true;
            }
            $hospitalUniverse = [];
            foreach ($specialityUniverse as $departments) {
                $hospitalUniverse += $departments;
            }

            /** @var array<string, array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>> $bound member => metric => segments */
            $bound = [];
            foreach ($members as $key => $member) {
                foreach (ClosureVolumeMetricKind::all() as $metric) {
                    $lists = [];
                    foreach ($metric->bindingUrgencies() as $urgency) {
                        $lists[] = $member['urgency'][$urgency] ?? [];
                    }
                    $bound[$key][$metric->value] = ClosureVolumeSegments::intersect($lists);
                }
            }

            foreach ($members as $key => $member) {
                $all = ClosureVolumeSegments::union($member['all']);
                foreach (ClosureVolumeMetricKind::all() as $metric) {
                    $segments = $bound[$key][$metric->value];
                    $anchors[] = new ClosureVolumeAnchor(
                        $eventId,
                        $hospitalId,
                        'department',
                        $member['speciality'],
                        $member['department'],
                        $metric,
                        [] === $segments ? ClosureVolumeRelevance::None : ClosureVolumeRelevance::Closed,
                        [] === $segments ? 0 : 1,
                        [] === $segments ? $all : $segments,
                    );
                }
            }

            $bySpeciality = [];
            foreach ($members as $key => $member) {
                $bySpeciality[$member['speciality']][] = $key;
            }
            foreach ($bySpeciality as $specialityId => $keys) {
                $anchors = [...$anchors, ...$this->aggregateAnchors($eventId, $hospitalId, 'speciality', $specialityId, $keys, \count($specialityUniverse[$specialityId] ?? []), $members, $bound)];
            }
            $anchors = [...$anchors, ...$this->aggregateAnchors($eventId, $hospitalId, 'hospital', 0, array_keys($members), \count($hospitalUniverse), $members, $bound)];
        }

        return $anchors;
    }

    /**
     * @param list<string>                                                                                                                                                                                           $keys    closed members (speciality|department) of the population in this event
     * @param array<string, array{speciality: int, department: int, all: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>, urgency: array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>}> $members
     * @param array<string, array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>>>                                                                                                                $bound
     *
     * @return list<ClosureVolumeAnchor>
     */
    private function aggregateAnchors(
        int $eventId,
        int $hospitalId,
        string $scope,
        int $specialityId,
        array $keys,
        int $universe,
        array $members,
        array $bound,
    ): array {
        $all = [];
        foreach ($keys as $key) {
            $all = [...$all, ...$members[$key]['all']];
        }
        $all = ClosureVolumeSegments::union($all);
        $anchors = [];
        foreach (ClosureVolumeMetricKind::all() as $metric) {
            $segments = [];
            $closedDepartments = [];
            foreach ($keys as $key) {
                if ([] !== $bound[$key][$metric->value]) {
                    $closedDepartments[$members[$key]['department']] = true;
                    $segments = [...$segments, ...$bound[$key][$metric->value]];
                }
            }
            $closed = \count($closedDepartments);
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
}
