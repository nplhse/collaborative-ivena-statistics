<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final class ClosureVolumeDeviation
{
    public static function absolute(?float $observed, ?float $expected): ?float
    {
        if (null === $observed || null === $expected) {
            return null;
        }

        return $observed - $expected;
    }

    public static function relative(?float $observed, ?float $expected): ?float
    {
        if (null === $observed || null === $expected || abs($expected) < 0.0000001) {
            return null;
        }

        return ($observed - $expected) / $expected;
    }

    public static function share(?float $numerator, ?float $denominator): ?float
    {
        if (null === $numerator || null === $denominator || abs($denominator) < 0.0000001) {
            return null;
        }

        return $numerator / $denominator;
    }
}
