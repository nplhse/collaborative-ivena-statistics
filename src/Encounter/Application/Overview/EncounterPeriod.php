<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Period of the encounter overview (#1055): whole calendar days, both bounds inclusive and optional.
 * A visit belongs to the period by its time anchor (`encounter.anchor_at`: first assessment contact,
 * else ED admission; local wall clock as exchanged), the same day the import coverage uses.
 * Internally the period is the half-open window `[from 00:00, to + 1 day 00:00)`.
 *
 * The encounter analytics page in Statistics builds it from the statistics period window
 * (`StatisticsPeriodResolver`: last 12 months, all time, year, quarter, month) with fromBounds().
 */
final readonly class EncounterPeriod
{
    private function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
    ) {
    }

    public static function allTime(): self
    {
        return new self(null, null);
    }

    /**
     * Takes a half-open window `[from, toExclusive)` whose bounds are midnights, as the statistics
     * period resolver returns it. Times of day are dropped.
     */
    public static function fromBounds(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): self
    {
        $fromDay = $from instanceof \DateTimeImmutable ? new \DateTimeImmutable($from->format('Y-m-d')) : null;
        $toDay = $toExclusive instanceof \DateTimeImmutable ? new \DateTimeImmutable($toExclusive->format('Y-m-d'))->modify('-1 day') : null;

        return new self($fromDay, $toDay);
    }

    /** First instant after the period, or null when it has no upper bound. */
    public function endExclusive(): ?\DateTimeImmutable
    {
        return $this->to?->modify('+1 day');
    }

    public function isAllTime(): bool
    {
        return !$this->from instanceof \DateTimeImmutable && !$this->to instanceof \DateTimeImmutable;
    }
}
