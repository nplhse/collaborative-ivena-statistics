<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

final class TransportTimeProfileMath
{
    public const int SMALL_SAMPLE_THRESHOLD = 10;

    public const int INSIGHT_MIN_BUCKET_N = 20;

    public const int INSIGHT_MIN_POPULATION_N = 50;

    public const int INSIGHT_MIN_DEVIATION_N = 30;

    public const float INSIGHT_MIN_PP = 5.0;

    public const float INSIGHT_MIN_OVERALL_PP = 8.0;

    public const int INSIGHT_MIN_RANK_CHANGE = 2;

    public static function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(100 * $numerator / $denominator, 1);
    }

    public static function deltaPp(float $bucketPercent, float $overallPercent): float
    {
        return round($bucketPercent - $overallPercent, 1);
    }

    public static function isSmallSample(int $n): bool
    {
        return $n > 0 && $n < self::SMALL_SAMPLE_THRESHOLD;
    }

    public static function heatClass(float $deltaPp, bool $smallSample): string
    {
        if (0.0 === $deltaPp) {
            return '';
        }

        $scale = $smallSample ? 0.35 : 1.0;
        $heat = max(-1.0, min(1.0, ($deltaPp / 20.0) * $scale));
        $abs = abs($heat);
        if ($abs < 0.08) {
            return '';
        }

        $level = $abs >= 0.6 ? '3' : ($abs >= 0.3 ? '2' : '1');
        $direction = $heat < 0 ? 'low' : 'high';

        return 'stats-ttp-heat-'.$direction.'-'.$level;
    }

    public static function deltaBadgeClass(string $heatClass): string
    {
        if (str_contains($heatClass, 'high')) {
            return 'bg-green-lt';
        }

        if (str_contains($heatClass, 'low')) {
            return 'bg-red-lt';
        }

        return '';
    }

    public static function rankBadgeClass(?int $rankDelta, bool $enteredTop): string
    {
        if ($enteredTop) {
            return 'bg-blue-lt';
        }

        if (null === $rankDelta || 0 === $rankDelta) {
            return '';
        }

        return $rankDelta > 0 ? 'bg-green-lt' : 'bg-red-lt';
    }

    public static function rankShiftClass(?int $rankDelta, bool $enteredTop): string
    {
        if ($enteredTop) {
            return 'stats-ttp-rank-shift-new';
        }

        if (null === $rankDelta || abs($rankDelta) < self::INSIGHT_MIN_RANK_CHANGE) {
            return '';
        }

        return $rankDelta > 0 ? 'stats-ttp-rank-shift-up' : 'stats-ttp-rank-shift-down';
    }
}
