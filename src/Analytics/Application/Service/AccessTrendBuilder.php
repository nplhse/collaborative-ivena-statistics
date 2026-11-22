<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AccessPeriod;
use App\Analytics\Application\DTO\AccessTrend;
use App\Analytics\Domain\AnalyticsCalendar;

/**
 * Lists counted accesses per month and year (#950, #1001). Pure calculation, no I/O.
 *
 * The months arrive summed by the database; years are summed from them here. The counting starts
 * at the first day with data. Every month and year from there to the current one is listed, with
 * zero when nothing was counted. Days before the start are not counted and not shown as zero.
 */
final class AccessTrendBuilder
{
    /**
     * @param list<array{month: string, firstDay: string, authenticated: int, anonymous: int}> $months ordered by month, `Y-m` and `Y-m-d` in Europe/Berlin
     * @param \DateTimeImmutable                                                               $today  Berlin calendar day the overview is built on
     */
    public function build(array $months, \DateTimeImmutable $today): AccessTrend
    {
        $today = AnalyticsCalendar::startOfDay($today);
        $months = array_values(array_filter(
            $months,
            static fn (array $month): bool => $month['month'] <= $today->format('Y-m'),
        ));

        if ([] === $months) {
            return new AccessTrend(null, 0, 0, [], []);
        }

        $countingSince = $this->parseDay($months[0]['firstDay']);

        $authenticated = 0;
        $anonymous = 0;
        /** @var array<string, array{authenticated: int, anonymous: int}> $byMonth */
        $byMonth = [];
        /** @var array<string, array{authenticated: int, anonymous: int}> $byYear */
        $byYear = [];
        foreach ($months as $month) {
            $authenticated += $month['authenticated'];
            $anonymous += $month['anonymous'];

            $key = $month['month'];
            $year = substr($key, 0, 4);
            $byMonth[$key] = ['authenticated' => $month['authenticated'], 'anonymous' => $month['anonymous']];
            $byYear[$year]['authenticated'] = ($byYear[$year]['authenticated'] ?? 0) + $month['authenticated'];
            $byYear[$year]['anonymous'] = ($byYear[$year]['anonymous'] ?? 0) + $month['anonymous'];
        }

        $months = [];
        $monthStart = $countingSince->modify('first day of this month');
        while ($monthStart <= $today) {
            $next = $monthStart->modify('first day of next month');
            $key = $monthStart->format('Y-m');
            $months[] = new AccessPeriod(
                key: $key,
                start: $monthStart,
                authenticated: $byMonth[$key]['authenticated'] ?? 0,
                anonymous: $byMonth[$key]['anonymous'] ?? 0,
                isCurrent: $today >= $monthStart && $today < $next,
                startsBeforeCounting: $countingSince > $monthStart,
            );
            $monthStart = $next;
        }

        $years = [];
        $yearStart = $countingSince->modify('first day of january this year');
        while ($yearStart <= $today) {
            $next = $yearStart->modify('first day of january next year');
            $key = $yearStart->format('Y');
            $years[] = new AccessPeriod(
                key: $key,
                start: $yearStart,
                authenticated: $byYear[$key]['authenticated'] ?? 0,
                anonymous: $byYear[$key]['anonymous'] ?? 0,
                isCurrent: $today >= $yearStart && $today < $next,
                startsBeforeCounting: $countingSince > $yearStart,
            );
            $yearStart = $next;
        }

        return new AccessTrend($countingSince, $authenticated, $anonymous, $years, $months);
    }

    private function parseDay(string $date): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, AnalyticsCalendar::timezone());
        if (!$day instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(sprintf('Invalid day "%s".', $date));
        }

        return $day;
    }
}
