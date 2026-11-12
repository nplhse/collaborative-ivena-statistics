<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Which allocations a matcher run looks at: those of one hospital whose IVENA arrival falls on one of
 * the given calendar days (local time). Candidate visits are then taken from the pass windows around
 * them, whatever their day.
 */
final readonly class MatchScope
{
    /** @var list<string> distinct days `Y-m-d`, ascending */
    public array $days;

    /**
     * @param list<string> $days           calendar days `Y-m-d`
     * @param int|null     $ignoreImportId allocations and visits of this import are left out (it is being deleted)
     */
    public function __construct(
        public int $hospitalId,
        array $days,
        public ?int $ignoreImportId = null,
    ) {
        foreach ($days as $day) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $day) {
                throw new \InvalidArgumentException(sprintf('Expected a day like 2026-10-12, got "%s".', $day));
            }
        }

        $days = array_values(array_unique($days));
        sort($days);
        $this->days = $days;
    }

    /**
     * Scope of the allocations that can have a visit anchored on one of the given days as candidate:
     * the matching anchor may lie on the day before or after the arrival (windows up to a few hours,
     * ED admission and first assessment contact may differ), so each day is widened by one day on both
     * sides. A wider scope only recomputes proposals; links and decisions never change.
     *
     * @param list<string> $visitDays
     */
    public static function aroundVisitDays(int $hospitalId, array $visitDays, ?int $ignoreImportId = null): self
    {
        $days = [];
        foreach ($visitDays as $day) {
            $date = new \DateTimeImmutable($day);
            foreach (['-1 day', '+0 days', '+1 day'] as $offset) {
                $days[] = $date->modify($offset)->format('Y-m-d');
            }
        }

        return new self($hospitalId, $days, $ignoreImportId);
    }

    public static function forPeriod(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        if ($to < $from) {
            throw new \InvalidArgumentException('The period ends before it starts.');
        }

        $days = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return new self($hospitalId, $days);
    }

    /**
     * Runs of consecutive days as half-open ranges [start 00:00, day after the last 00:00).
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function ranges(): array
    {
        $ranges = [];
        $start = null;
        $end = null;
        foreach ($this->days as $day) {
            $dayStart = new \DateTimeImmutable($day.' 00:00:00');
            if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable) {
                if ($end == $dayStart) {
                    $end = $dayStart->modify('+1 day');

                    continue;
                }

                $ranges[] = [$start, $end];
            }

            $start = $dayStart;
            $end = $dayStart->modify('+1 day');
        }

        if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable) {
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
