<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;

/**
 * Stepwise navigation (previous / next / parent) for calendar periods on the statistics overview.
 */
final readonly class StatisticsPeriodNavigation
{
    public function __construct(
        private ProjectionEarliestDateProviderInterface $earliestDateProvider,
    ) {
    }

    public function isPreviousEnabled(StatisticsFilter $filter): bool
    {
        return $this->previous($filter) instanceof StatisticsFilter;
    }

    public function isNextEnabled(StatisticsFilter $filter): bool
    {
        return $this->next($filter) instanceof StatisticsFilter;
    }

    public function isParentEnabled(StatisticsFilter $filter): bool
    {
        return $this->parent($filter) instanceof StatisticsFilter;
    }

    public function previous(StatisticsFilter $filter): ?StatisticsFilter
    {
        return match ($filter->period) {
            StatisticsFilterPeriod::Year => $this->stepYear($filter, -1),
            StatisticsFilterPeriod::Quarter => $this->stepQuarter($filter, -1),
            StatisticsFilterPeriod::Month => $this->stepMonth($filter, -1),
            default => null,
        };
    }

    public function next(StatisticsFilter $filter): ?StatisticsFilter
    {
        return match ($filter->period) {
            StatisticsFilterPeriod::Year => $this->stepYear($filter, 1),
            StatisticsFilterPeriod::Quarter => $this->stepQuarter($filter, 1),
            StatisticsFilterPeriod::Month => $this->stepMonth($filter, 1),
            default => null,
        };
    }

    public function parent(StatisticsFilter $filter): ?StatisticsFilter
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');

        return match ($filter->period) {
            StatisticsFilterPeriod::Month => $this->withPeriod(
                $filter,
                StatisticsFilterPeriod::Quarter,
                $year,
                null,
                (int) ceil(($filter->referenceMonth ?? (int) $now->format('n')) / 3),
            ),
            StatisticsFilterPeriod::Quarter => $this->withPeriod(
                $filter,
                StatisticsFilterPeriod::Year,
                $year,
                null,
                null,
            ),
            StatisticsFilterPeriod::Year => $this->withPeriod(
                $filter,
                StatisticsFilterPeriod::AllTime,
                null,
                null,
                null,
            ),
            default => null,
        };
    }

    public function earliestYear(): int
    {
        $earliest = $this->earliestDateProvider->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            return (int) $earliest->format('Y');
        }

        return (int) new \DateTimeImmutable()->format('Y');
    }

    public function currentYear(): int
    {
        return (int) new \DateTimeImmutable()->format('Y');
    }

    private function stepYear(StatisticsFilter $filter, int $delta): ?StatisticsFilter
    {
        $now = new \DateTimeImmutable();
        $year = ($filter->referenceYear ?? (int) $now->format('Y')) + $delta;
        $candidate = $this->withPeriod($filter, StatisticsFilterPeriod::Year, $year, null, null);
        if (!$this->isWithinBounds($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function stepQuarter(StatisticsFilter $filter, int $delta): ?StatisticsFilter
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $quarter = $filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3);
        $absoluteQuarter = ($year * 4) + ($quarter - 1) + $delta;
        $year = intdiv($absoluteQuarter, 4);
        $quarter = ($absoluteQuarter % 4) + 1;

        $candidate = $this->withPeriod($filter, StatisticsFilterPeriod::Quarter, $year, null, $quarter);
        if (!$this->isWithinBounds($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function stepMonth(StatisticsFilter $filter, int $delta): ?StatisticsFilter
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $month = $filter->referenceMonth ?? (int) $now->format('n');
        $anchor = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $anchor = $anchor->modify(sprintf('%+d month', $delta));
        $candidate = $this->withPeriod(
            $filter,
            StatisticsFilterPeriod::Month,
            (int) $anchor->format('Y'),
            (int) $anchor->format('n'),
            null,
        );
        if (!$this->isWithinBounds($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isWithinBounds(StatisticsFilter $filter): bool
    {
        if (!\in_array($filter->period, [StatisticsFilterPeriod::Year, StatisticsFilterPeriod::Quarter, StatisticsFilterPeriod::Month], true)) {
            return true;
        }

        $bounds = StatisticsPeriodResolver::resolve($filter);
        \assert($bounds->from instanceof \DateTimeImmutable);

        $earliest = $this->earliestDateProvider->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            $earliestMonth = $earliest->modify('first day of this month')->setTime(0, 0, 0);
            if ($bounds->from < $earliestMonth) {
                return false;
            }
        }

        $today = new \DateTimeImmutable('today');

        return $bounds->from <= $today;
    }

    private function withPeriod(
        StatisticsFilter $filter,
        StatisticsFilterPeriod $period,
        ?int $year,
        ?int $month,
        ?int $quarter,
    ): StatisticsFilter {
        return new StatisticsFilter(
            $filter->scope,
            $filter->hospitalId,
            $filter->cohortType,
            $period,
            $year,
            $month,
            $quarter,
            $filter->notice,
            false,
            $filter->stateId,
            $filter->dispatchAreaId,
        );
    }
}
