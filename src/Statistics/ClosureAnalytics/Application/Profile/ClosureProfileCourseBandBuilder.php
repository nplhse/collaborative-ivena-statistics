<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

/**
 * Mirrors {@see assets/lib/build-closure-course-chart-options.js} band semantics for aggregated profile courses.
 */
final class ClosureProfileCourseBandBuilder
{
    /**
     * @param list<array{offset: int|float, closureShare: float|int, ...}> $points
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function bands(array $points, bool $alignToEnd): array
    {
        $activeIndexes = [];
        $zeroIndex = null;
        $pointCount = \count($points);
        for ($index = 0; $index < $pointCount; ++$index) {
            $point = $points[$index];
            if ((float) ($point['closureShare'] ?? 0) > 0) {
                $activeIndexes[] = $index;
            }
            if (null === $zeroIndex && 0 === (int) ($point['offset'] ?? 0)) {
                $zeroIndex = $index;
            }
        }
        if ([] === $activeIndexes) {
            return [];
        }

        $fromIndex = $alignToEnd ? min($activeIndexes) : max(0, $zeroIndex ?? 0);
        $toCandidates = $activeIndexes;
        if ($alignToEnd) {
            $toCandidates[] = null !== $zeroIndex ? $zeroIndex - 1 : max($activeIndexes);
        }
        $toIndex = max($toCandidates);
        if ($toIndex < $fromIndex) {
            return [];
        }

        return [[$fromIndex, $toIndex]];
    }

    /**
     * @param list<array{offset: int|float, ...}> $points
     */
    public static function anchorCategory(array $points): ?string
    {
        foreach ($points as $point) {
            if (0 === (int) ($point['offset'] ?? 0)) {
                return sprintf('%+d h', 0);
            }
        }

        return null;
    }
}
