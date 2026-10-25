<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Time ranges for which a hospital has imported data, as half-open wall-clock ranges.
 * Built from import export periods; a covered hour with zero allocations is a real zero,
 * an uncovered hour is missing data.
 */
final readonly class ClosureCoverage
{
    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges sorted, disjoint, non-touching
     */
    private function __construct(private array $ranges)
    {
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     */
    public static function fromRanges(array $ranges): self
    {
        $ranges = array_values(array_filter(
            array_map(
                static fn (array $range): array => [ClosureVolumeClock::wall($range[0]), ClosureVolumeClock::wall($range[1])],
                $ranges,
            ),
            static fn (array $range): bool => $range[1] > $range[0],
        ));
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);
            if (null === $last || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
                continue;
            }
            if ($end > $merged[$last][1]) {
                $merged[$last] = [$merged[$last][0], $end];
            }
        }

        return new self(array_values($merged));
    }

    public function isEmpty(): bool
    {
        return [] === $this->ranges;
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> uncovered ranges between the first and last covered range
     */
    public function gaps(): array
    {
        $gaps = [];
        for ($index = 1, $count = \count($this->ranges); $index < $count; ++$index) {
            $gaps[] = [$this->ranges[$index - 1][1], $this->ranges[$index][0]];
        }

        return $gaps;
    }

    public function covers(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $from = ClosureVolumeClock::wall($from);
        $to = ClosureVolumeClock::wall($to);
        foreach ($this->ranges as [$start, $end]) {
            if ($start <= $from && $end >= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * End of the contiguous covered range that contains $at, or null when $at is not covered.
     */
    public function coveredUntil(\DateTimeImmutable $at): ?\DateTimeImmutable
    {
        $at = ClosureVolumeClock::wall($at);
        foreach ($this->ranges as [$start, $end]) {
            if ($start <= $at && $at < $end) {
                return $end;
            }
        }

        return null;
    }

    /**
     * Calendar days fully covered between $from and $to.
     *
     * @return array<string, true> key Y-m-d
     */
    public function coveredDays(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = ClosureVolumeClock::wall($from);
        $to = ClosureVolumeClock::wall($to);
        $days = [];
        foreach ($this->ranges as [$start, $end]) {
            if ($end <= $from || $start >= $to) {
                continue;
            }
            $day = $start->setTime(0, 0);
            if ($day < $start) {
                $day = $day->modify('+1 day');
            }
            $lower = $from->setTime(0, 0);
            if ($day < $lower) {
                $day = $lower;
            }
            while ($day < $to) {
                $next = $day->modify('+1 day');
                if ($next > $end) {
                    break;
                }
                $days[$day->format('Y-m-d')] = true;
                $day = $next;
            }
        }

        return $days;
    }
}
