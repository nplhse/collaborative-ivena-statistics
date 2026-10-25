<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Set operations on half-open time segments [start, end).
 * Results are sorted and disjoint; touching segments are merged.
 */
final class ClosureVolumeSegments
{
    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function union(array $segments): array
    {
        $segments = array_values(array_filter($segments, static fn (array $segment): bool => $segment[0] < $segment[1]));
        usort($segments, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        $merged = [];
        foreach ($segments as [$start, $end]) {
            $last = \count($merged) - 1;
            if ($last < 0 || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
                continue;
            }
            if ($end > $merged[$last][1]) {
                $merged[$last] = [$merged[$last][0], $end];
            }
        }

        return array_values($merged);
    }

    /**
     * Hours covered by every list at the same time.
     *
     * @param list<list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $lists
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function intersect(array $lists): array
    {
        if ([] === $lists) {
            return [];
        }
        $result = self::union(array_shift($lists));
        foreach ($lists as $list) {
            $result = self::intersectTwo($result, self::union($list));
            if ([] === $result) {
                return [];
            }
        }

        return $result;
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $left
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $right
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private static function intersectTwo(array $left, array $right): array
    {
        $result = [];
        $i = 0;
        $j = 0;
        while ($i < \count($left) && $j < \count($right)) {
            $start = max($left[$i][0], $right[$j][0]);
            $end = min($left[$i][1], $right[$j][1]);
            if ($start < $end) {
                $result[] = [$start, $end];
            }
            if ($left[$i][1] < $right[$j][1]) {
                ++$i;
            } else {
                ++$j;
            }
        }

        return $result;
    }
}
