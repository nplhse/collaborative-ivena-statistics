<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Half-open interval [from, toExclusive) for createdAt filtering;
 * toExclusive null means lower bound only (rolling window style).
 */
final readonly class StatisticsPeriodBounds
{
    public function __construct(
        public \DateTimeImmutable $from,
        public ?\DateTimeImmutable $toExclusive = null,
    ) {
    }
}
