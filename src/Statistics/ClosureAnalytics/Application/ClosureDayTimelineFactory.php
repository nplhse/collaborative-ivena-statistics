<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineDay;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineItem;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineLane;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineSegment;

final class ClosureDayTimelineFactory
{
    public const string TIMEZONE = 'Europe/Berlin';

    /** @var list<int> */
    private const array TICK_HOURS = [0, 4, 8, 12, 16, 20, 24];

    /**
     * @param list<ClosureDayTimelineItem> $items
     *
     * @return list<ClosureDayTimelineDay>
     */
    public function build(array $items): array
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $days = [];
        foreach ($items as $item) {
            $start = $item->startsAt->setTimezone($timezone);
            $end = $item->endsAt->setTimezone($timezone);
            if ($end <= $start) {
                continue;
            }

            for ($dayStart = $start->setTime(0, 0); $dayStart < $end; $dayStart = $dayStart->modify('+1 day')) {
                $dayEnd = $dayStart->modify('+1 day');
                $segmentStart = $start > $dayStart ? $start : $dayStart;
                $segmentEnd = $end < $dayEnd ? $end : $dayEnd;
                if ($segmentEnd <= $segmentStart) {
                    continue;
                }

                $dayKey = $dayStart->format('Y-m-d');
                $daySeconds = max(1, $dayEnd->getTimestamp() - $dayStart->getTimestamp());
                $left = 100.0 * (float) ($segmentStart->getTimestamp() - $dayStart->getTimestamp()) / (float) $daySeconds;
                $width = 100.0 * (float) ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / (float) $daySeconds;
                $laneKey = $item->departmentName."\0".$item->careLevel;
                $days[$dayKey] ??= [
                    'dayStart' => $dayStart,
                    'dayEnd' => $dayEnd,
                    'lanes' => [],
                ];
                $days[$dayKey]['lanes'][$laneKey] ??= [
                    'departmentName' => $item->departmentName,
                    'specialityName' => $item->specialityName,
                    'careLevel' => $item->careLevel,
                    'primary' => false,
                    'segments' => [],
                ];
                if ($item->primary && !$days[$dayKey]['lanes'][$laneKey]['primary']) {
                    $days[$dayKey]['lanes'][$laneKey]['specialityName'] = $item->specialityName;
                    $days[$dayKey]['lanes'][$laneKey]['primary'] = true;
                }
                $days[$dayKey]['lanes'][$laneKey]['segments'][] = new ClosureDayTimelineSegment(
                    max(0.0, min(100.0, $left)),
                    max(0.25, min(100.0 - $left, $width)),
                    $item->url,
                    $item->title,
                    $item->primary,
                    $item->eventType,
                );
            }
        }

        ksort($days);

        $result = [];
        foreach ($days as $dayKey => $day) {
            $lanes = $day['lanes'];
            ksort($lanes);
            $result[] = new ClosureDayTimelineDay(
                $dayKey,
                $this->ticks($day['dayStart'], $day['dayEnd']),
                array_map($this->lane(...), array_values($lanes)),
            );
        }

        return $result;
    }

    /**
     * @param list<ClosureDayTimelineDay> $days
     *
     * @return list<string>
     */
    public static function contextEventTypes(array $days): array
    {
        $present = [];
        foreach ($days as $day) {
            foreach ($day->lanes as $lane) {
                foreach ($lane->segments as $segment) {
                    if (!$segment->primary) {
                        $present[$segment->eventType->value] = true;
                    }
                }
            }
        }

        return array_values(array_filter(
            ['group', 'cluster', 'single'],
            static fn (string $type): bool => isset($present[$type]),
        ));
    }

    public static function calendarDayBounds(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): StatisticsPeriodBounds
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $start = $startsAt->setTimezone($timezone)->setTime(0, 0);
        $end = $endsAt->setTimezone($timezone);
        $midnight = $end->setTime(0, 0);
        $endExclusive = $end == $midnight ? $midnight : $midnight->modify('+1 day');
        if ($endExclusive <= $start) {
            $endExclusive = $start->modify('+1 day');
        }

        return new StatisticsPeriodBounds($start, $endExclusive);
    }

    /**
     * @param array{
     *     departmentName: string,
     *     specialityName: string,
     *     careLevel: string,
     *     primary: bool,
     *     segments: list<ClosureDayTimelineSegment>
     * } $lane
     */
    private function lane(array $lane): ClosureDayTimelineLane
    {
        $segments = $lane['segments'];
        usort(
            $segments,
            static fn (ClosureDayTimelineSegment $left, ClosureDayTimelineSegment $right): int => [$left->primary, $left->left]
                <=> [$right->primary, $right->left],
        );

        return new ClosureDayTimelineLane(
            $lane['departmentName'],
            $lane['specialityName'],
            $lane['careLevel'],
            $segments,
        );
    }

    /**
     * @return list<array{label: string, position: float}>
     */
    private function ticks(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        $daySeconds = max(1, $dayEnd->getTimestamp() - $dayStart->getTimestamp());

        return array_map(
            static fn (int $hour): array => [
                'label' => sprintf('%02d:00', $hour),
                'position' => 100 * (
                    (24 === $hour ? $dayEnd : $dayStart->setTime($hour, 0))->getTimestamp()
                    - $dayStart->getTimestamp()
                ) / $daySeconds,
            ],
            self::TICK_HOURS,
        );
    }
}
