<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Splits half-open intervals on Europe/Berlin clock hours and measures epoch seconds.
 * Stored closure and allocation timestamps are naive wall-clock values.
 */
final class ClosureVolumeClock
{
    public const string TIMEZONE = 'Europe/Berlin';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }

    public static function wall(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant->format('Y-m-d H:i:s'), self::zone());
    }

    /**
     * Day-time buckets match allocation_stats_projection: night 0–5, morning 6–11, afternoon 12–17, evening 18–23.
     */
    public static function dayTimeBucket(int $hour): int
    {
        return match (true) {
            $hour < 6 => 1,
            $hour < 12 => 2,
            $hour < 18 => 3,
            default => 4,
        };
    }

    /**
     * @return list<ClosureVolumeBucket>
     */
    public static function split(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $start = self::wall($start);
        $end = self::wall($end);
        if ($start >= $end) {
            return [];
        }

        $buckets = [];
        $cursor = $start->getTimestamp();
        $endTs = $end->getTimestamp();
        while ($cursor < $endTs) {
            $at = new \DateTimeImmutable('@'.$cursor)->setTimezone(self::zone());
            $secondsIntoHour = ((int) $at->format('i')) * 60 + (int) $at->format('s');
            $pieceEnd = min($endTs, $cursor + (3600 - $secondsIntoHour));
            if ($pieceEnd <= $cursor) {
                break;
            }
            $pieceEndAt = new \DateTimeImmutable('@'.$pieceEnd)->setTimezone(self::zone());
            $hour = (int) $at->format('G');
            $buckets[] = new ClosureVolumeBucket(
                $at,
                $pieceEndAt,
                (int) $at->format('N'),
                $hour,
                self::dayTimeBucket($hour),
                $pieceEnd - $cursor,
            );
            $cursor = $pieceEnd;
        }

        return $buckets;
    }

    public static function overlapSeconds(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): int {
        $start = self::wall($start);
        $end = self::wall($end);
        if ($from instanceof \DateTimeImmutable) {
            $bound = self::wall($from);
            if ($bound > $start) {
                $start = $bound;
            }
        }
        if ($toExclusive instanceof \DateTimeImmutable) {
            $bound = self::wall($toExclusive);
            if ($bound < $end) {
                $end = $bound;
            }
        }

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }
}
