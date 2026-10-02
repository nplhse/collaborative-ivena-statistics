<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

final class ClosureDurationFormatter
{
    public static function minutesFromSeconds(int|float $seconds): int
    {
        return (int) round(max(0.0, (float) $seconds) / 60.0);
    }

    public static function humanize(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $weeks = intdiv($minutes, 10_080);
        $days = intdiv($minutes % 10_080, 1_440);
        $hours = intdiv($minutes % 1_440, 60);
        $remainingMinutes = $minutes % 60;

        $parts = [];
        if ($weeks > 0 || $days > 0) {
            if ($weeks > 0) {
                $parts[] = $weeks.' w';
            }
            if ($days > 0) {
                $parts[] = $days.' d';
            }
            if ($hours > 0) {
                $parts[] = $hours.' h';
            }

            return implode(' ', $parts);
        }

        if ($hours > 0) {
            $parts[] = $hours.' h';
        }
        if ($remainingMinutes > 0 || [] === $parts) {
            $parts[] = $remainingMinutes.' min';
        }

        return implode(' ', $parts);
    }
}
