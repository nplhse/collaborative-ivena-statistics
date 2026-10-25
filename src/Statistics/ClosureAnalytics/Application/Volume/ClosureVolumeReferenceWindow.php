<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Weeks before the event start and after the event end that feed the reference.
 * The event extent, including gaps between its segments, is never part of it.
 */
final readonly class ClosureVolumeReferenceWindow
{
    public const string SYMMETRIC = 'symmetric';
    public const string BACKWARD = 'backward';

    public function __construct(
        public int $weeksBefore,
        public int $weeksAfter,
    ) {
        if ($this->weeksBefore < 0 || $this->weeksAfter < 0 || 0 === $this->weeksBefore + $this->weeksAfter) {
            throw new \InvalidArgumentException('A reference window needs at least one week and no negative side.');
        }
    }

    /**
     * Named comparison windows for rebuilds: `symmetric` is the configured default,
     * `backward` keeps the pre-ADR 015 window (the same number of weeks, all before the event).
     */
    public static function named(string $name, self $configured): self
    {
        return match ($name) {
            self::SYMMETRIC => $configured,
            self::BACKWARD => new self($configured->weeksBefore + $configured->weeksAfter, 0),
            default => throw new \InvalidArgumentException(sprintf('Unknown reference window "%s". Use %s or %s.', $name, self::SYMMETRIC, self::BACKWARD)),
        };
    }

    public function totalWeeks(): int
    {
        return $this->weeksBefore + $this->weeksAfter;
    }

    /**
     * Wall-clock ranges [from, to) that may hold reference hours.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function ranges(\DateTimeImmutable $eventStart, \DateTimeImmutable $eventEnd): array
    {
        $start = ClosureVolumeClock::wall($eventStart);
        $end = ClosureVolumeClock::wall($eventEnd);
        $ranges = [];
        if ($this->weeksBefore > 0) {
            $ranges[] = [$start->modify(sprintf('-%d weeks', $this->weeksBefore)), $start];
        }
        if ($this->weeksAfter > 0) {
            $ranges[] = [$end, $end->modify(sprintf('+%d weeks', $this->weeksAfter))];
        }

        return $ranges;
    }

    /**
     * Earliest and latest instant any reference hour of these events can touch.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function loadRange(\DateTimeImmutable $firstStart, \DateTimeImmutable $lastEnd): array
    {
        return [
            ClosureVolumeClock::wall($firstStart)->modify(sprintf('-%d weeks', $this->weeksBefore)),
            ClosureVolumeClock::wall($lastEnd)->modify(sprintf('+%d weeks', $this->weeksAfter)),
        ];
    }
}
