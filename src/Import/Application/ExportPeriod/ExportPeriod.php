<?php

declare(strict_types=1);

namespace App\Import\Application\ExportPeriod;

/**
 * Half-open wall-clock period [startsAt, endsAt) covered by one import file.
 */
final readonly class ExportPeriod
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('An export period must end after it starts.');
        }
    }

    /**
     * @param list<self> $periods
     *
     * @return list<self> sorted, overlapping or touching periods merged
     */
    public static function merge(array $periods): array
    {
        usort($periods, static fn (self $left, self $right): int => $left->startsAt <=> $right->startsAt);
        $merged = [];
        foreach ($periods as $period) {
            $last = array_key_last($merged);
            if (null === $last || $period->startsAt > $merged[$last]->endsAt) {
                $merged[] = $period;
                continue;
            }
            if ($period->endsAt > $merged[$last]->endsAt) {
                $merged[$last] = new self($merged[$last]->startsAt, $period->endsAt);
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<self> $periods
     */
    public static function coveredBy(self $candidate, array $periods): bool
    {
        foreach (self::merge($periods) as $period) {
            if ($period->startsAt <= $candidate->startsAt && $period->endsAt >= $candidate->endsAt) {
                return true;
            }
        }

        return false;
    }

    public function equals(self $other): bool
    {
        return $this->startsAt->format('Y-m-d H:i:s') === $other->startsAt->format('Y-m-d H:i:s')
            && $this->endsAt->format('Y-m-d H:i:s') === $other->endsAt->format('Y-m-d H:i:s');
    }
}
