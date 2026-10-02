<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;

/**
 * Quartiles use {@see DescriptiveStatisticsCalculator}: linear interpolation
 * between neighbouring ranks (Hyndman-Fan type 7). Whiskers follow the
 * 1.5-IQR rule and stop at the outermost values still inside the fences.
 * Fewer than five observations are individual values, not a box.
 */
final readonly class ClosureDurationDistribution
{
    public const int BOX_MINIMUM = 5;

    public function __construct(private DescriptiveStatisticsCalculator $statistics)
    {
    }

    /**
     * @param list<int> $valueSeconds
     */
    public function summarize(string $key, string $label, array $valueSeconds): ClosureDurationDistributionGroup
    {
        $values = $valueSeconds;
        sort($values, SORT_NUMERIC);
        $stats = $this->statistics->calculate($values);
        $count = \count($values);
        $lowerQuartile = $stats->p25 ?? 0.0;
        $upperQuartile = $stats->p75 ?? 0.0;
        $showBox = $count >= self::BOX_MINIMUM;
        $whiskerLow = null;
        $whiskerHigh = null;
        $outliers = [];

        if ($showBox) {
            $fence = 1.5 * ($upperQuartile - $lowerQuartile);
            $lowerFence = $lowerQuartile - $fence;
            $upperFence = $upperQuartile + $fence;
            $inside = [];
            foreach ($values as $value) {
                if ((float) $value < $lowerFence || (float) $value > $upperFence) {
                    $outliers[] = (float) $value;
                    continue;
                }
                $inside[] = $value;
            }
            if ([] !== $inside) {
                $whiskerLow = (float) min($inside);
                $whiskerHigh = (float) max($inside);
            }
        }

        return new ClosureDurationDistributionGroup(
            $key,
            $label,
            $count,
            $stats->median ?? 0.0,
            $stats->mean ?? 0.0,
            $stats->minimum ?? 0,
            $stats->maximum ?? 0,
            $lowerQuartile,
            $upperQuartile,
            $showBox,
            $whiskerLow,
            $whiskerHigh,
            $outliers,
            $values,
        );
    }
}
