<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AccessPeriod;
use App\Analytics\Application\DTO\AccessTrend;
use App\Analytics\Domain\AnalyticsCalendar;

/**
 * Sums daily counted accesses into years and months (#950). Pure calculation, no I/O.
 *
 * The counting starts at the first day with data. Every month and year from there to the current
 * one is listed, with zero when nothing was counted. Days before the start are not counted and
 * not shown as zero.
 */
final class AccessTrendBuilder
{
    /**
     * @param list<array{date: string, authenticated: int, anonymous: int}> $days  ordered by date, `Y-m-d` in Europe/Berlin
     * @param \DateTimeImmutable                                            $today Berlin calendar day the overview is built on
     */
    public function build(array $days, \DateTimeImmutable $today): AccessTrend
    {
        $today = AnalyticsCalendar::startOfDay($today);
        $days = array_values(array_filter(
            $days,
            static fn (array $day): bool => $day['date'] <= $today->format('Y-m-d'),
        ));

        if ([] === $days) {
            return new AccessTrend(null, 0, 0, [], []);
        }

        $countingSince = $this->parseDay($days[0]['date']);

        $authenticated = 0;
        $anonymous = 0;
        /** @var array<string, array{authenticated: int, anonymous: int}> $byMonth */
        $byMonth = [];
        /** @var array<string, array{authenticated: int, anonymous: int}> $byYear */
        $byYear = [];
        foreach ($days as $day) {
            $authenticated += $day['authenticated'];
            $anonymous += $day['anonymous'];

            $month = substr($day['date'], 0, 7);
            $year = substr($day['date'], 0, 4);
            $byMonth[$month]['authenticated'] = ($byMonth[$month]['authenticated'] ?? 0) + $day['authenticated'];
            $byMonth[$month]['anonymous'] = ($byMonth[$month]['anonymous'] ?? 0) + $day['anonymous'];
            $byYear[$year]['authenticated'] = ($byYear[$year]['authenticated'] ?? 0) + $day['authenticated'];
            $byYear[$year]['anonymous'] = ($byYear[$year]['anonymous'] ?? 0) + $day['anonymous'];
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
