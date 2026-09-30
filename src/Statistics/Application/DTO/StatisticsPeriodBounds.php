<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Half-open interval [from, toExclusive) for createdAt filtering.
 *
 * from null: no lower bound (all_time without pseudo-dates).
 * toExclusive null: open-ended upper bound (rolling window style).
 */
final readonly class StatisticsPeriodBounds
{
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $toExclusive = null,
    ) {
    }

    public function intersect(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): self
    {
        return new self(
            $this->later($this->from, $from),
            $this->earlier($this->toExclusive, $toExclusive),
        );
    }

    private function later(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if (!$left instanceof \DateTimeImmutable) {
            return $right;
        }
        if (!$right instanceof \DateTimeImmutable) {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }

    private function earlier(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if (!$left instanceof \DateTimeImmutable) {
            return $right;
        }
        if (!$right instanceof \DateTimeImmutable) {
            return $left;
        }

        return $left <= $right ? $left : $right;
    }
}
