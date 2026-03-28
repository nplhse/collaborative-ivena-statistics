<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

/**
 * Visualisation for grouped distributions only.
 */
enum DistributionGroupedChartMode: string
{
    /** 100 % stacked bars (share within each primary category). */
    case PercentStacked = 'percent';

    /** Side-by-side bars with raw counts. */
    case AbsoluteGrouped = 'absolute';

    public static function tryFromQuery(mixed $value): ?self
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return self::tryFrom($value);
    }
}
