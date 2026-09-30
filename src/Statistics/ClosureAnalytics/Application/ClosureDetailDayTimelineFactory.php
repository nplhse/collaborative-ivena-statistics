<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineDay;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineItem;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureSameDayInterval;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureDetailDayTimelineFactory
{
    public function __construct(
        private ClosureDayTimelineFactory $dayTimelineFactory,
        private ClosureEventQuery $eventQuery,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<ClosureIntervalRow> $focus
     * @param array<string, mixed>     $query
     *
     * @return array{0: list<ClosureDayTimelineDay>, 1: list<string>}
     */
    public function build(
        ClosureAnalyticsCriteria $criteria,
        array $focus,
        string $excludeEventKey,
        array $query,
    ): array {
        $items = [];
        foreach ($focus as $interval) {
            $items[] = $this->item(
                $interval->startsAt,
                $interval->endsAt,
                $interval->departmentName,
                $interval->specialityName,
                $interval->careLevel,
                $this->urlGenerator->generate('app_stats_closure_analytics_interval', [...$query, 'id' => $interval->id]),
                $this->timeTitle($interval->startsAt, $interval->endsAt, $interval->departmentName),
                true,
                $interval->eventType,
            );
        }

        foreach ($this->related($criteria, $focus, $excludeEventKey) as $interval) {
            $items[] = $this->item(
                $interval->startsAt,
                $interval->endsAt,
                $interval->departmentName,
                $interval->specialityName,
                $interval->careLevel,
                $this->urlGenerator->generate('app_stats_closure_analytics_event', [...$query, 'eventKey' => $interval->eventKey]),
                $this->eventTitle($interval),
                false,
                $interval->eventType,
            );
        }

        $days = $this->dayTimelineFactory->build($items);

        return [$days, ClosureDayTimelineFactory::contextEventTypes($days)];
    }

    /**
     * @param list<ClosureIntervalRow> $focus
     *
     * @return list<ClosureSameDayInterval>
     */
    private function related(ClosureAnalyticsCriteria $criteria, array $focus, string $excludeEventKey): array
    {
        $departmentIds = array_values(array_unique(array_map(
            static fn (ClosureIntervalRow $interval): int => $interval->departmentId,
            $focus,
        )));
        $hospitalIds = array_values(array_unique(array_map(
            static fn (ClosureIntervalRow $interval): int => $interval->hospitalId,
            $focus,
        )));
        $bounds = $this->focusBounds($focus);
        if ([] === $focus || [] === $departmentIds || !$bounds instanceof StatisticsPeriodBounds) {
            return [];
        }

        return $this->eventQuery->fetchSameDayDepartmentIntervals(
            $criteria->withSameDayDepartments($bounds, $departmentIds, $hospitalIds),
            $excludeEventKey,
        );
    }

    /**
     * @param list<ClosureIntervalRow> $focus
     */
    private function focusBounds(array $focus): ?StatisticsPeriodBounds
    {
        $starts = null;
        $ends = null;
        foreach ($focus as $interval) {
            $starts = $starts instanceof \DateTimeImmutable && $starts <= $interval->startsAt ? $starts : $interval->startsAt;
            $ends = $ends instanceof \DateTimeImmutable && $ends >= $interval->endsAt ? $ends : $interval->endsAt;
        }

        return $starts instanceof \DateTimeImmutable && $ends instanceof \DateTimeImmutable
            ? ClosureDayTimelineFactory::calendarDayBounds($starts, $ends)
            : null;
    }

    private function item(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $departmentName,
        string $specialityName,
        string $careLevel,
        string $url,
        string $title,
        bool $primary,
        ClosureEventType $eventType,
    ): ClosureDayTimelineItem {
        return new ClosureDayTimelineItem(
            $startsAt,
            $endsAt,
            $departmentName,
            $specialityName,
            $careLevel,
            $url,
            $title,
            $primary,
            $eventType,
        );
    }

    private function eventTitle(ClosureSameDayInterval $interval): string
    {
        return sprintf(
            '%s · %s',
            $this->translator->trans('stats.closure.events.'.$interval->eventType->value, [], 'statistics'),
            $this->timeTitle($interval->startsAt, $interval->endsAt, $interval->departmentName),
        );
    }

    private function timeTitle(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $departmentName): string
    {
        $timezone = new \DateTimeZone(ClosureDayTimelineFactory::TIMEZONE);

        return sprintf(
            '%s–%s · %s',
            $startsAt->setTimezone($timezone)->format('H:i'),
            $endsAt->setTimezone($timezone)->format('H:i'),
            $departmentName,
        );
    }
}
