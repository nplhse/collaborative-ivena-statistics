<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

/**
 * Share and delta helpers that never invent percentages for empty denominators.
 */
final class ClosedDepartmentShareMath
{
    public static function percent(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(100 * $numerator / $denominator, 1);
    }

    public static function deltaPp(?float $closedPercent, ?float $regularPercent): ?float
    {
        if (null === $closedPercent || null === $regularPercent) {
            return null;
        }

        return round($closedPercent - $regularPercent, 1);
    }

    public static function deltaMinutes(?float $closedMinutes, ?float $regularMinutes): ?float
    {
        if (null === $closedMinutes || null === $regularMinutes) {
            return null;
        }

        return round($closedMinutes - $regularMinutes, 1);
    }
}
