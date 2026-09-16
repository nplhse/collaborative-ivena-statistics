<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

/**
 * Share and percentage-point helpers. Ratios stay unrounded; only {@see roundDisplay()} rounds for the UI.
 */
final class GeographicSegmentShareMath
{
    public static function percent(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return 100.0 * (float) $numerator / (float) $denominator;
    }

    public static function deltaPp(?float $segmentPercent, ?float $referencePercent): ?float
    {
        if (null === $segmentPercent || null === $referencePercent) {
            return null;
        }

        return $segmentPercent - $referencePercent;
    }

    public static function roundDisplay(?float $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return round($value, 1);
    }
}
