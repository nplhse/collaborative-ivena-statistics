<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

/**
 * Period of the match review: whole days from `from` to `to`, both inclusive (#1054). Allocations count by
 * their IVENA arrival, visits by their matching anchor, both on the local wall clock as stored.
 */
final readonly class ReviewPeriod
{
    /** Longest period one review page reads, so swap proposals stay cheap. */
    public const int MAX_DAYS = 92;

    public \DateTimeImmutable $from;

    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diff($to)->days >= self::MAX_DAYS) {
            $to = $from->modify(sprintf('+%d days', self::MAX_DAYS - 1));
        }

        $this->from = $from;
        $this->to = $to;
    }

    /**
     * The calendar month of the given day.
     */
    public static function monthOf(\DateTimeImmutable $day): self
    {
        $first = $day->modify('first day of this month');

        return new self($first, $first->modify('last day of this month'));
    }

    /**
     * Parses `Y-m-d` values; falls back to the given period when a value is missing or invalid.
     */
    public static function fromStrings(?string $from, ?string $to, self $fallback): self
    {
        $parse = static function (?string $value): ?\DateTimeImmutable {
            if (null === $value || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $date || $date->format('Y-m-d') !== $value ? null : $date;
        };

        $fromDate = $parse($from);
        $toDate = $parse($to);
        if (!$fromDate instanceof \DateTimeImmutable && !$toDate instanceof \DateTimeImmutable) {
            return $fallback;
        }

        return new self($fromDate ?? $fallback->from, $toDate ?? $fallback->to);
    }

    /** First instant after the period. */
    public function toExclusive(): \DateTimeImmutable
    {
        return $this->to->modify('+1 day');
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod Used by the review templates.
     *
     * @return array{from: string, to: string}
     */
    public function query(): array
    {
        return ['from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')];
    }
}
