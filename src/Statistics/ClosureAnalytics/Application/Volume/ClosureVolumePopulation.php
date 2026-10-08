<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Care level limits which urgencies a closure opens.
 * `other` stays its own source category and is mapped to SK1–SK3 only here.
 * Speciality and department are separate evaluation levels, not a summed population.
 */
final class ClosureVolumePopulation
{
    /** @return list<int> */
    public static function urgenciesForCareLevel(?string $careLevel): array
    {
        return match ($careLevel) {
            'emergency' => [1],
            'inpatient' => [2],
            'outpatient' => [3],
            default => [1, 2, 3],
        };
    }

    public static function covers(string $careLevel, ClosureVolumeStratum $stratum): bool
    {
        $urgency = $stratum->urgencyCode();
        if (null === $urgency) {
            return true;
        }

        return \in_array($urgency, self::urgenciesForCareLevel($careLevel), true);
    }
}
