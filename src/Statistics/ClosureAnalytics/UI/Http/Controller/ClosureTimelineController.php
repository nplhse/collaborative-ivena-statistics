<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ClosureTimelineController extends AbstractController
{
    private const array GRAINS = ['year', 'quarter', 'month', 'week', 'day', 'event'];

    public function __construct(
        private readonly ClosureAnalyticsCriteriaFactory $criteriaFactory,
        private readonly ClosureTemporalQuery $temporalQuery,
    ) {
    }

    #[Route('/statistics/closure-analytics/timeline/frame', name: 'app_stats_closure_analytics_timeline_frame', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if (StatisticsFilterScope::DispatchArea === $filter->scope) {
            throw $this->createNotFoundException();
        }

        $grain = $request->query->get('timeline_grain', $this->maximumGrain($filter->period));
        if (!\in_array($grain, self::GRAINS, true)) {
            $grain = $this->maximumGrain($filter->period);
        }
        $grain = $this->clampGrain($grain, $filter->period);
        $criteria = $this->windowedCriteria(
            $this->timelineCriteria(
                $this->criteriaFactory->create($user, $filter, ClosureAnalyticsFilterRequestResolver::fromRequest($request)),
            ),
            $grain,
            $request->query->get('timeline_from'),
        );
        $query = $request->query->all();

        if ('day' === $grain || 'event' === $grain) {
            return $this->render('@Statistics/closure_analytics/_timeline_frame.html.twig', [
                'timelineGrain' => $grain,
                'timelineRows' => [],
                'timelineSegmentRows' => $this->segmentRows($criteria, $query),
                'timelineWeeklyRows' => [],
                'timelineWeekDays' => [],
                'timelineWeekTicks' => [],
                'timelineBreadcrumbs' => $this->breadcrumbs($query, $grain, $criteria),
                'timelineScrollableYears' => false,
            ]);
        }

        $rows = [];
        foreach ($this->temporalQuery->fetchTimelineGrid($criteria, $grain) as $gridRow) {
            $next = $this->nextGrain($grain);
            $cells = [];
            foreach ($gridRow->cells as $cell) {
                $navigationStart = $this->cellNavigationStart($cell->key, $cell->startsAt, $grain);
                $cells[] = [
                    'cell' => $cell,
                    'label' => $this->cellLabel($cell->startsAt, $grain),
                    'url' => $this->generateUrl('app_stats_closure_analytics_timeline_frame', [
                        ...$query,
                        'timeline_grain' => $next,
                        'timeline_from' => $navigationStart->format('Y-m-d'),
                    ]),
                ];
            }
            $rows[] = [
                'grid' => $gridRow,
                'cells' => $cells,
                'closedMinutes' => array_sum(array_map(static fn (array $item): int => $item['cell']->closedMinutes, $cells)),
                'eventCount' => array_sum(array_map(static fn (array $item): int => $item['cell']->eventCount, $cells)),
            ];
        }

        return $this->render('@Statistics/closure_analytics/_timeline_frame.html.twig', [
            'timelineGrain' => $grain,
            'timelineRows' => $rows,
            'timelineSegmentRows' => [],
            'timelineWeeklyRows' => \in_array($grain, ['quarter', 'month', 'week'], true)
                ? $this->periodDepartmentRows($criteria, $query)
                : [],
            'timelineWeekDays' => \in_array($grain, ['quarter', 'month', 'week'], true)
                ? $this->periodCells($criteria, $grain, $query)
                : [],
            'timelineWeekTicks' => \in_array($grain, ['quarter', 'month', 'week'], true)
                ? $this->periodTicks($criteria, $grain)
                : [],
            'timelineBreadcrumbs' => $this->breadcrumbs($query, $grain, $criteria),
            'timelineScrollableYears' => StatisticsFilterPeriod::AllTime === $filter->period && 'year' === $grain,
        ]);
    }

    private function maximumGrain(StatisticsFilterPeriod $period): string
    {
        return match ($period) {
            StatisticsFilterPeriod::Month => 'month',
            StatisticsFilterPeriod::Quarter => 'quarter',
            StatisticsFilterPeriod::Year,
            StatisticsFilterPeriod::All,
            StatisticsFilterPeriod::AllTime => 'year',
        };
    }

    private function clampGrain(string $grain, StatisticsFilterPeriod $period): string
    {
        $maximum = $this->maximumGrain($period);

        return array_search($grain, self::GRAINS, true) < array_search($maximum, self::GRAINS, true)
            ? $maximum
            : $grain;
    }

    private function timelineCriteria(ClosureAnalyticsCriteria $criteria): ClosureAnalyticsCriteria
    {
        if (StatisticsFilterPeriod::All !== $criteria->filter->period) {
            return $criteria;
        }

        $to = new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('Europe/Berlin'));
        $from = $to->modify('-12 months');

        return $criteria->withPeriod(new StatisticsPeriodBounds($from, $to));
    }

    private function windowedCriteria(ClosureAnalyticsCriteria $criteria, string $grain, mixed $fromInput): ClosureAnalyticsCriteria
    {
        if ('year' === $grain || !\is_string($fromInput) || '' === $fromInput) {
            return $criteria;
        }

        try {
            $from = new \DateTimeImmutable($fromInput.' 00:00:00', new \DateTimeZone('Europe/Berlin'));
        } catch (\Exception) {
            return $criteria;
        }
        $to = match ($grain) {
            'quarter' => $from->modify('+3 months'),
            'month' => $from->modify('+1 month'),
            'week' => $from->modify('+1 week'),
            'day', 'event' => $from->modify('+1 day'),
            default => $criteria->period->toExclusive,
        };
        if ($criteria->period->from instanceof \DateTimeImmutable && $from < $criteria->period->from) {
            $from = $criteria->period->from;
        }
        if ($criteria->period->toExclusive instanceof \DateTimeImmutable && (null === $to || $to > $criteria->period->toExclusive)) {
            $to = $criteria->period->toExclusive;
        }

        return $criteria->withPeriod(new StatisticsPeriodBounds($from, $to));
    }

    private function nextGrain(string $grain): string
    {
        return match ($grain) {
            'year' => 'quarter',
            'quarter' => 'month',
            'month' => 'week',
            'week' => 'day',
            default => 'event',
        };
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array{label: string, translate: bool, url: ?string}>
     */
    private function breadcrumbs(
        array $query,
        string $grain,
        ClosureAnalyticsCriteria $criteria,
    ): array {
        $levels = array_slice(
            self::GRAINS,
            (int) array_search($this->maximumGrain($criteria->filter->period), self::GRAINS, true),
        );
        $currentIndex = array_search($grain, $levels, true);
        if (false === $currentIndex) {
            $currentIndex = 0;
        }

        $from = $criteria->period->from;
        try {
            if (isset($query['timeline_from']) && \is_string($query['timeline_from'])) {
                $from = new \DateTimeImmutable($query['timeline_from'], new \DateTimeZone('Europe/Berlin'));
            }
        } catch (\Exception) {
            $from = null;
        }

        $breadcrumbs = [];
        foreach (array_slice($levels, 0, $currentIndex + 1) as $level) {
            $levelFrom = $from instanceof \DateTimeImmutable ? $this->normalizeBreadcrumbDate($from, $level) : null;
            $isCurrent = $level === $grain;
            $label = $this->breadcrumbLabel($level, $levelFrom, $criteria->filter->period);
            $breadcrumbs[] = [
                'label' => $label,
                'translate' => str_starts_with($label, 'stats.'),
                'url' => $isCurrent ? null : $this->breadcrumbUrl($query, $level, $levelFrom),
            ];
        }

        return $breadcrumbs;
    }

    private function normalizeBreadcrumbDate(
        \DateTimeImmutable $from,
        string $grain,
    ): \DateTimeImmutable {
        return match ($grain) {
            'year' => $from->setDate((int) $from->format('Y'), 1, 1)->setTime(0, 0),
            'quarter' => $from->setDate(
                (int) $from->format('Y'),
                ((int) floor(((int) $from->format('n') - 1) / 3)) * 3 + 1,
                1,
            )->setTime(0, 0),
            'month' => $from->modify('first day of this month')->setTime(0, 0),
            'week' => $from->modify('monday this week')->setTime(0, 0),
            default => $from->setTime(0, 0),
        };
    }

    private function breadcrumbLabel(
        string $grain,
        ?\DateTimeImmutable $from,
        StatisticsFilterPeriod $period,
    ): string {
        if ('year' === $grain) {
            return StatisticsFilterPeriod::Year === $period && $from instanceof \DateTimeImmutable
                ? $from->format('Y')
                : 'stats.closure.timeline.breadcrumb.overview';
        }
        if ('event' === $grain) {
            return 'stats.closure.timeline.breadcrumb.events';
        }
        if (!$from instanceof \DateTimeImmutable) {
            return 'stats.closure.timeline.breadcrumb.'.$grain;
        }

        return match ($grain) {
            'quarter' => sprintf('Q%d %s', (int) ceil(((int) $from->format('n')) / 3), $from->format('Y')),
            'month' => $from->format('m/Y'),
            'week' => sprintf('KW %s/%s', $from->format('W'), $from->format('o')),
            default => $from->format('d.m.Y'),
        };
    }

    /**
     * @param array<string, mixed> $query
     */
    private function breadcrumbUrl(
        array $query,
        string $grain,
        ?\DateTimeImmutable $from,
    ): string {
        $query['timeline_grain'] = $grain;
        if (!$from instanceof \DateTimeImmutable) {
            unset($query['timeline_from']);
        } else {
            $query['timeline_from'] = $from->format('Y-m-d');
        }

        return $this->generateUrl('app_stats_closure_analytics_timeline_frame', $query);
    }

    private function cellLabel(\DateTimeImmutable $start, string $grain): string
    {
        $local = $start->setTimezone(new \DateTimeZone('Europe/Berlin'));

        return match ($grain) {
            'year' => 'Q'.(int) ceil(((int) $local->format('n')) / 3),
            'quarter' => $local->format('M'),
            'month' => 'KW '.$local->format('W'),
            'week' => $local->format('D d.m.'),
            default => $local->format('H:i'),
        };
    }

    private function cellNavigationStart(
        string $key,
        \DateTimeImmutable $start,
        string $grain,
    ): \DateTimeImmutable {
        if ('month' === $grain && 1 === preg_match('/^(\d{4})-W(\d{2})$/', $key, $matches)) {
            return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'))
                ->setISODate((int) $matches[1], (int) $matches[2])
                ->setTime(0, 0);
        }

        return $start->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function periodDepartmentRows(ClosureAnalyticsCriteria $criteria, array $query): array
    {
        if (!$criteria->period->from instanceof \DateTimeImmutable || !$criteria->period->toExclusive instanceof \DateTimeImmutable) {
            return [];
        }

        $fromTimestamp = $criteria->period->from->getTimestamp();
        $toTimestamp = $criteria->period->toExclusive->getTimestamp();
        $duration = max(1, $toTimestamp - $fromTimestamp);
        $timezone = new \DateTimeZone('Europe/Berlin');
        /** @var array<string, array{
         *     groupKey: string,
         *     hospitalName: string,
         *     specialityName: string,
         *     departmentName: string,
         *     careLevelName: string,
         *     rawSegments: list<array{start: int, end: int, parallel: bool, eventKey: string, eventType: string, urls: array<string, true>}>
         * }> $lanes
         */
        $lanes = [];

        foreach ($this->temporalQuery->fetchTimelineSegments($criteria) as $segment) {
            $key = implode('|', [
                $segment->hospitalId,
                $segment->specialityName,
                $segment->departmentName,
                $segment->careLevelName,
            ]);
            $groupKey = implode('|', [
                $segment->hospitalId,
                $segment->specialityName,
                $segment->departmentName,
            ]);
            $url = ClosureEventType::Single !== $segment->eventType
                ? $this->generateUrl('app_stats_closure_analytics_event', [...$query, 'eventKey' => $segment->eventKey])
                : $this->generateUrl('app_stats_closure_analytics_interval', [...$query, 'id' => $segment->intervalId]);
            $lanes[$key] ??= [
                'groupKey' => $groupKey,
                'hospitalName' => $segment->hospitalName,
                'specialityName' => $segment->specialityName,
                'departmentName' => $segment->departmentName,
                'careLevelName' => $segment->careLevelName,
                'rawSegments' => [],
            ];
            $lanes[$key]['rawSegments'][] = [
                'start' => $segment->startsAt->getTimestamp(),
                'end' => $segment->endsAt->getTimestamp(),
                'parallel' => $segment->parallel,
                'eventKey' => $segment->eventKey,
                'eventType' => $segment->eventType->value,
                'urls' => [$url => true],
            ];
        }

        $rows = [];
        foreach ($lanes as $lane) {
            usort(
                $lane['rawSegments'],
                static fn (array $left, array $right): int => $left['start'] <=> $right['start'],
            );
            /** @var list<array{start: int, end: int, parallel: bool, eventKey: string, eventType: string, urls: array<string, true>}> $merged */
            $merged = [];
            foreach ($lane['rawSegments'] as $rawSegment) {
                $lastIndex = array_key_last($merged);
                if (
                    null === $lastIndex
                    || $rawSegment['start'] > $merged[$lastIndex]['end']
                    || $rawSegment['eventKey'] !== $merged[$lastIndex]['eventKey']
                ) {
                    $merged[] = $rawSegment;
                    continue;
                }
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $rawSegment['end']);
                $merged[$lastIndex]['parallel'] = $merged[$lastIndex]['parallel'] || $rawSegment['parallel'];
                $merged[$lastIndex]['urls'] += $rawSegment['urls'];
            }

            $segments = [];
            foreach ($merged as $segment) {
                $start = max($fromTimestamp, $segment['start']);
                $end = min($toTimestamp, $segment['end']);
                $urls = array_keys($segment['urls']);
                $segments[] = [
                    'left' => 100 * ($start - $fromTimestamp) / $duration,
                    'width' => max(0.15, 100 * ($end - $start) / $duration),
                    'parallel' => $segment['parallel'],
                    'eventType' => $segment['eventType'],
                    'url' => 1 === \count($urls) ? $urls[0] : null,
                    'title' => sprintf(
                        '%s–%s',
                        new \DateTimeImmutable('@'.$start)->setTimezone($timezone)->format('D H:i'),
                        new \DateTimeImmutable('@'.$end)->setTimezone($timezone)->format('D H:i'),
                    ),
                ];
            }

            $rows[] = [
                'groupKey' => $lane['groupKey'],
                'hospitalName' => $lane['hospitalName'],
                'specialityName' => $lane['specialityName'],
                'departmentName' => $lane['departmentName'],
                'careLevelName' => $lane['careLevelName'],
                'segments' => $segments,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array{label: string, left: float, width: float, url: string}>
     */
    private function periodCells(
        ClosureAnalyticsCriteria $criteria,
        string $grain,
        array $query,
    ): array {
        if (!$criteria->period->from instanceof \DateTimeImmutable || !$criteria->period->toExclusive instanceof \DateTimeImmutable) {
            return [];
        }

        $timezone = new \DateTimeZone('Europe/Berlin');
        $from = $criteria->period->from->setTimezone($timezone);
        $to = $criteria->period->toExclusive->setTimezone($timezone);
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $cells = [];
        for ($cell = $from; $cell < $to; $cell = $cellEnd) {
            $cellEnd = 'quarter' === $grain ? $cell->modify('monday next week') : $cell->modify('+1 day');
            if ($cellEnd > $to) {
                $cellEnd = $to;
            }
            $targetGrain = 'quarter' === $grain ? 'week' : 'day';
            $targetFrom = 'quarter' === $grain ? $cell->modify('monday this week') : $cell;
            $cells[] = [
                'label' => match ($grain) {
                    'quarter' => 'KW '.$cell->format('W'),
                    'month' => $cell->format('d.m.'),
                    default => $cell->format('D d.m.'),
                },
                'left' => 100 * ($cell->getTimestamp() - $from->getTimestamp()) / $duration,
                'width' => 100 * ($cellEnd->getTimestamp() - $cell->getTimestamp()) / $duration,
                'url' => $this->generateUrl('app_stats_closure_analytics_timeline_frame', [
                    ...$query,
                    'timeline_grain' => $targetGrain,
                    'timeline_from' => $targetFrom->format('Y-m-d'),
                ]),
            ];
        }

        return $cells;
    }

    /**
     * @return list<float>
     */
    private function periodTicks(ClosureAnalyticsCriteria $criteria, string $grain): array
    {
        if (!$criteria->period->from instanceof \DateTimeImmutable || !$criteria->period->toExclusive instanceof \DateTimeImmutable) {
            return [];
        }

        $timezone = new \DateTimeZone('Europe/Berlin');
        $from = $criteria->period->from->setTimezone($timezone);
        $to = $criteria->period->toExclusive->setTimezone($timezone);
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $ticks = [];
        $step = 'week' === $grain ? '+6 hours' : '+1 day';
        for ($tick = $from->modify($step); $tick < $to; $tick = $tick->modify($step)) {
            $ticks[] = 100 * ($tick->getTimestamp() - $from->getTimestamp()) / $duration;
        }

        return $ticks;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function segmentRows(ClosureAnalyticsCriteria $criteria, array $query): array
    {
        $rows = [];
        $timezone = new \DateTimeZone('Europe/Berlin');
        foreach ($this->temporalQuery->fetchTimelineSegments($criteria) as $segment) {
            $key = implode('|', [
                $segment->dayKey,
                $segment->hospitalId,
                $segment->specialityName,
                $segment->careLevelName,
                $segment->departmentName,
            ]);
            $dayStart = new \DateTimeImmutable($segment->dayKey.' 00:00:00', $timezone);
            $dayEnd = $dayStart->modify('+1 day');
            $daySeconds = $dayEnd->getTimestamp() - $dayStart->getTimestamp();
            $left = 100.0 * (float) ($segment->startsAt->getTimestamp() - $dayStart->getTimestamp()) / (float) $daySeconds;
            $width = 100.0 * (float) ($segment->endsAt->getTimestamp() - $segment->startsAt->getTimestamp()) / (float) $daySeconds;
            $url = ClosureEventType::Single !== $segment->eventType
                ? $this->generateUrl('app_stats_closure_analytics_event', [...$query, 'eventKey' => $segment->eventKey])
                : $this->generateUrl('app_stats_closure_analytics_interval', [...$query, 'id' => $segment->intervalId]);
            $rows[$key] ??= [
                'dayKey' => $segment->dayKey,
                'groupKey' => implode('|', [
                    $segment->dayKey,
                    $segment->hospitalId,
                    $segment->specialityName,
                    $segment->departmentName,
                ]),
                'hospitalName' => $segment->hospitalName,
                'specialityName' => $segment->specialityName,
                'careLevelName' => $segment->careLevelName,
                'departmentName' => $segment->departmentName,
                'ticks' => array_map(
                    static fn (int $hour): array => [
                        'label' => sprintf('%02d:00', $hour),
                        'position' => 100 * (
                            (24 === $hour ? $dayEnd : $dayStart->setTime($hour, 0))->getTimestamp()
                            - $dayStart->getTimestamp()
                        ) / $daySeconds,
                    ],
                    [0, 4, 8, 12, 16, 20, 24],
                ),
                'segments' => [],
            ];
            $rows[$key]['segments'][] = [
                'left' => max(0.0, min(100.0, $left)),
                'width' => max(0.25, min(100.0 - $left, $width)),
                'url' => $url,
                'title' => sprintf(
                    '%s–%s · %s',
                    $segment->startsAt->setTimezone($timezone)->format('H:i'),
                    $segment->endsAt->setTimezone($timezone)->format('H:i'),
                    $segment->sourceGroupId ?? $segment->departmentName,
                ),
                'eventType' => $segment->eventType->value,
                'parallel' => $segment->parallel,
            ];
        }

        return array_values($rows);
    }
}
