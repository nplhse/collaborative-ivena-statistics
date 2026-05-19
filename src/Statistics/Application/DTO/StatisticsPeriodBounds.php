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
}
