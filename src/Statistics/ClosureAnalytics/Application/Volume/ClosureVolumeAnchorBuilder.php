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
