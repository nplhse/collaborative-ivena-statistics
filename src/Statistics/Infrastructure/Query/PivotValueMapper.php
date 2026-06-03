<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;

final class PivotValueMapper
{
    public const string AGE_BUCKET_CASE = <<<'DQL'
CASE WHEN a.age IS NULL THEN 'unknown' WHEN a.age <= 18 THEN '0_18' WHEN a.age <= 29 THEN '19_29' WHEN a.age <= 39 THEN '30_39' WHEN a.age <= 49 THEN '40_49' WHEN a.age <= 59 THEN '50_59' WHEN a.age <= 69 THEN '60_69' WHEN a.age <= 79 THEN '70_79' WHEN a.age <= 89 THEN '80_89' WHEN a.age <= 99 THEN '90_99' ELSE 'unknown' END
DQL;

    public function genderKeyFromCode(int $genderCode): string
    {
        return match ($genderCode) {
            AllocationStatsGenderProjectionCode::Male->value => 'M',
            AllocationStatsGenderProjectionCode::Female->value => 'F',
            AllocationStatsGenderProjectionCode::Other->value => 'X',
            default => '',
        };
    }
}
