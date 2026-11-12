<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\CoveredPeriod;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\MonthCoverageState;
use App\Allocation\Application\DTO\MyHospitals\MonthGap;
use App\Allocation\Application\DTO\MyHospitals\MonthStatus;

/**
 * Classifies every month of a hospital's history (see {@see MonthCoverageState}). Coverage comes from the covered
 * periods of relevant imports, volume from the projection. Months are compared by calendar day, never by time.
 */
final class MonthlyCoverageClassifier
{
    /**
     * Months from the first month with data or coverage up to the current month, oldest first.
     *
     * @return list<MonthStatus>
     */
    public function classify(HospitalDataSnapshot $snapshot, \DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        $currentMonth = $today->modify('first day of this month');
        $intervals = $this->mergedIntervals($snapshot->coveredPeriods);

        $firstMonth = $this->firstMonth($snapshot, $intervals);
        if (!$firstMonth instanceof \DateTimeImmutable || $firstMonth > $currentMonth) {
            return [];
        }

        $firstCovered = [] === $intervals ? null : $intervals[0]->firstDay->modify('first day of this month');
        $lastCovered = [] === $intervals ? null : $intervals[\count($intervals) - 1]->lastDay->modify('first day of this month');

        $months = [];
        for ($month = $firstMonth; $month <= $currentMonth; $month = $month->modify('+1 month')) {
            $monthEnd = $month->modify('last day of this month');
            $days = (int) $monthEnd->format('j');
            $coveredDays = $this->coveredDays($intervals, $month, min($monthEnd, $today));
            $allocations = $snapshot->monthlyVolumes[$month->format('Y-m')] ?? 0;
            $outside = !$firstCovered instanceof \DateTimeImmutable || !$lastCovered instanceof \DateTimeImmutable || $month < $firstCovered || $month > $lastCovered;

            $state = match (true) {
                $month->format('Y-m') === $currentMonth->format('Y-m') => MonthCoverageState::InProgress,
                $coveredDays >= $days => $allocations > 0 ? MonthCoverageState::Covered : MonthCoverageState::CoveredZero,
                $coveredDays > 0, $allocations > 0 => MonthCoverageState::Partial,
                $outside => MonthCoverageState::Outside,
                default => MonthCoverageState::Missing,
            };

            $months[] = new MonthStatus($month, $state, $allocations, $coveredDays, $days);
        }

        return $months;
    }

    /**
     * The months from `$windowStart` to the current month; months before the history are outside.
     *
     * @param list<MonthStatus> $months result of {@see classify()}
     *
     * @return list<MonthStatus>
     */
    public function window(array $months, \DateTimeImmutable $windowStart, \DateTimeImmutable $now): array
    {
        $byMonth = [];
        foreach ($months as $status) {
            $byMonth[$status->month->format('Y-m')] = $status;
        }

        $currentMonth = $now->setTime(0, 0)->modify('first day of this month');
        $window = [];
        for ($month = $windowStart->setTime(0, 0)->modify('first day of this month'); $month <= $currentMonth; $month = $month->modify('+1 month')) {
            $window[] = $byMonth[$month->format('Y-m')] ?? new MonthStatus(
                $month,
                $month->format('Y-m') === $currentMonth->format('Y-m') ? MonthCoverageState::InProgress : MonthCoverageState::Outside,
                0,
                0,
                (int) $month->format('t'),
            );
        }

        return $window;
    }

    /**
     * @param list<MonthStatus> $months
     *
     * @return list<MonthGap>
     */
    public function gaps(array $months): array
    {
        $gaps = [];
        $start = null;
        $previous = null;
        $length = 0;
        foreach ($months as $status) {
            if (MonthCoverageState::Missing === $status->state) {
                $start ??= $status->month;
                $previous = $status->month;
                ++$length;

                continue;
            }
            if ($start instanceof \DateTimeImmutable && $previous instanceof \DateTimeImmutable) {
                $gaps[] = new MonthGap($start, $previous, $length);
            }
            $start = null;
            $previous = null;
            $length = 0;
        }
        if ($start instanceof \DateTimeImmutable && $previous instanceof \DateTimeImmutable) {
            $gaps[] = new MonthGap($start, $previous, $length);
        }

        return $gaps;
    }

    /**
     * @param list<CoveredPeriod> $intervals
     */
    private function firstMonth(HospitalDataSnapshot $snapshot, array $intervals): ?\DateTimeImmutable
    {
        $candidates = [];
        if ([] !== $intervals) {
            $candidates[] = $intervals[0]->firstDay;
        }
        if ($snapshot->firstAllocationAt instanceof \DateTimeImmutable) {
            $candidates[] = $snapshot->firstAllocationAt->setTime(0, 0);
        }

        return [] === $candidates ? null : min($candidates)->modify('first day of this month');
    }

    /**
     * Covered days are merged into disjoint, sorted periods so overlapping imports count once.
     *
     * @param list<CoveredPeriod> $periods
     *
     * @return list<CoveredPeriod>
     */
    private function mergedIntervals(array $periods): array
    {
        usort($periods, static fn (CoveredPeriod $a, CoveredPeriod $b): int => $a->firstDay <=> $b->firstDay);

        $merged = [];
        $first = null;
        $last = null;
        foreach ($periods as $period) {
            $periodFirst = $period->firstDay->setTime(0, 0);
            $periodLast = $period->lastDay->setTime(0, 0);
            if ($first instanceof \DateTimeImmutable && $last instanceof \DateTimeImmutable && $periodFirst <= $last->modify('+1 day')) {
                $last = max($last, $periodLast);

                continue;
            }
            if ($first instanceof \DateTimeImmutable && $last instanceof \DateTimeImmutable) {
                $merged[] = new CoveredPeriod($first, $last);
            }
            $first = $periodFirst;
            $last = $periodLast;
        }
        if ($first instanceof \DateTimeImmutable && $last instanceof \DateTimeImmutable) {
            $merged[] = new CoveredPeriod($first, $last);
        }

        return $merged;
    }

    /**
     * @param list<CoveredPeriod> $intervals
     */
    private function coveredDays(array $intervals, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = 0;
        foreach ($intervals as $interval) {
            $start = max($interval->firstDay, $from);
            $end = min($interval->lastDay, $to);
            if ($end >= $start) {
                $days += (int) $start->diff($end)->days + 1;
            }
        }

        return $days;
    }
}
