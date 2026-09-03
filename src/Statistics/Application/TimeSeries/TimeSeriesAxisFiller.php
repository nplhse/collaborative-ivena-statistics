<?php

declare(strict_types=1);

namespace App\Statistics\Application\TimeSeries;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;

/**
 * Completes a time-series axis with zero buckets inside the selected window.
 *
 * Incomplete buckets are never drawn: monthly series stop before the current calendar
 * month, daily series include today but not future days. Closed periods (year/quarter/month)
 * fill from the period start through that clipped end. Open periods (all / all_time) fill
 * from the first observed bucket through the clipped end. Empty input stays empty.
 */
final class TimeSeriesAxisFiller
{
    /**
     * @param array<string, int> $countsByIsoKey keyed by `YYYY-MM` or `YYYY-MM-DD`
     *
     * @return list<array{key: string, count: int}>
     */
    public static function fill(
        TimeSeriesGrain $grain,
        StatisticsPeriodBounds $bounds,
        array $countsByIsoKey,
        \DateTimeImmutable $now,
    ): array {
        $endExclusive = self::clipEndExclusive($grain, $bounds->toExclusive, $now);
        $countsByIsoKey = self::excludeIncompleteTail($grain, $countsByIsoKey, $endExclusive);
        if ([] === $countsByIsoKey) {
            return [];
        }

        $start = self::axisStart($grain, $bounds, $countsByIsoKey, $endExclusive);
        if (!$start instanceof \DateTimeImmutable || $start >= $endExclusive) {
            return self::observedInOrder($countsByIsoKey);
        }

        $filled = [];
        foreach (self::enumerateKeys($grain, $start, $endExclusive) as $key) {
            $filled[] = [
                'key' => $key,
                'count' => $countsByIsoKey[$key] ?? 0,
            ];
        }

        return $filled;
    }

    /**
     * Calendar month numbers (1–12) present in a closed period, clipped to {@see $now}.
     *
     * @return list<string>
     */
    public static function calendarMonthKeys(
        StatisticsPeriodBounds $bounds,
        \DateTimeImmutable $now,
    ): array {
        if (!$bounds->from instanceof \DateTimeImmutable || !$bounds->toExclusive instanceof \DateTimeImmutable) {
            return [];
        }

        $endExclusive = self::clipEndExclusive(TimeSeriesGrain::Month, $bounds->toExclusive, $now);
        $keys = [];
        foreach (self::enumerateKeys(TimeSeriesGrain::Month, $bounds->from, $endExclusive) as $isoKey) {
            $month = (int) substr($isoKey, 5, 2);
            $keys[] = (string) $month;
        }

        return $keys;
    }

    /**
     * @param array{year: int, month: int, day?: int, count: int} $row
     */
    public static function isoKeyFromRow(array $row, TimeSeriesGrain $grain): string
    {
        return match ($grain) {
            TimeSeriesGrain::Day => sprintf(
                '%04d-%02d-%02d',
                $row['year'],
                $row['month'],
                $row['day'] ?? 1,
            ),
            TimeSeriesGrain::Month => sprintf('%04d-%02d', $row['year'], $row['month']),
        };
    }

    /**
     * @param array<string, int> $countsByIsoKey
     *
     * @return list<array{key: string, count: int}>
     */
    private static function observedInOrder(array $countsByIsoKey): array
    {
        $keys = array_keys($countsByIsoKey);
        sort($keys, \SORT_STRING);

        $filled = [];
        foreach ($keys as $key) {
            $filled[] = [
                'key' => $key,
                'count' => $countsByIsoKey[$key],
            ];
        }

        return $filled;
    }

    /**
     * @param array<string, int> $countsByIsoKey
     */
    private static function axisStart(
        TimeSeriesGrain $grain,
        StatisticsPeriodBounds $bounds,
        array $countsByIsoKey,
        \DateTimeImmutable $endExclusive,
    ): ?\DateTimeImmutable {
        if ($bounds->toExclusive instanceof \DateTimeImmutable && $bounds->from instanceof \DateTimeImmutable) {
            return self::alignStart($grain, $bounds->from);
        }

        $firstKey = array_key_first($countsByIsoKey);
        foreach (array_keys($countsByIsoKey) as $key) {
            if ($key < $firstKey) {
                $firstKey = $key;
            }
        }

        if (!\is_string($firstKey) || '' === $firstKey) {
            return null;
        }

        $observed = self::parseIsoKey($grain, $firstKey);
        if (!$observed instanceof \DateTimeImmutable || $observed >= $endExclusive) {
            return null;
        }

        return $observed;
    }

    private static function clipEndExclusive(
        TimeSeriesGrain $grain,
        ?\DateTimeImmutable $toExclusive,
        \DateTimeImmutable $now,
    ): \DateTimeImmutable {
        $clip = match ($grain) {
            TimeSeriesGrain::Day => $now->setTime(0, 0, 0)->modify('+1 day'),
            TimeSeriesGrain::Month => $now->modify('first day of this month')->setTime(0, 0, 0),
        };

        if ($toExclusive instanceof \DateTimeImmutable && $toExclusive < $clip) {
            return $toExclusive;
        }

        return $clip;
    }

    /**
     * @return list<string>
     */
    private static function enumerateKeys(
        TimeSeriesGrain $grain,
        \DateTimeImmutable $start,
        \DateTimeImmutable $endExclusive,
    ): array {
        $cursor = self::alignStart($grain, $start);
        $keys = [];
        while ($cursor < $endExclusive) {
            $keys[] = match ($grain) {
                TimeSeriesGrain::Day => $cursor->format('Y-m-d'),
                TimeSeriesGrain::Month => $cursor->format('Y-m'),
            };
            $cursor = match ($grain) {
                TimeSeriesGrain::Day => $cursor->modify('+1 day'),
                TimeSeriesGrain::Month => $cursor->modify('+1 month'),
            };
        }

        return $keys;
    }

    private static function alignStart(TimeSeriesGrain $grain, \DateTimeImmutable $start): \DateTimeImmutable
    {
        return match ($grain) {
            TimeSeriesGrain::Day => $start->setTime(0, 0, 0),
            TimeSeriesGrain::Month => $start->modify('first day of this month')->setTime(0, 0, 0),
        };
    }

    /**
     * @param array<string, int> $countsByIsoKey
     *
     * @return array<string, int>
     */
    private static function excludeIncompleteTail(
        TimeSeriesGrain $grain,
        array $countsByIsoKey,
        \DateTimeImmutable $endExclusive,
    ): array {
        $cutoffKey = match ($grain) {
            TimeSeriesGrain::Day => $endExclusive->format('Y-m-d'),
            TimeSeriesGrain::Month => $endExclusive->format('Y-m'),
        };

        return array_filter(
            $countsByIsoKey,
            static fn (string $key): bool => $key < $cutoffKey,
            \ARRAY_FILTER_USE_KEY,
        );
    }

    private static function parseIsoKey(TimeSeriesGrain $grain, string $key): ?\DateTimeImmutable
    {
        $format = TimeSeriesGrain::Day === $grain ? 'Y-m-d' : 'Y-m';
        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $key);

        return $parsed instanceof \DateTimeImmutable ? $parsed : null;
    }
}
