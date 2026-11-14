<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Sex;

/**
 * Hand-checkable matcher inputs on 12 October 2026. Defaults: female, 70 years, ambulance, no physician,
 * no work accident, so a visit at the arrival time scores time +4, sex +2, age +2, route +1 = 9 points.
 */
trait MatchingTestData
{
    private static function allocation(int $id, string $arrival = '10:00', string $sex = 'F', int $age = 70, bool $withPhysician = false, bool $workAccident = false, ?int $pzc = null): MatchingAllocation
    {
        return new MatchingAllocation($id, new \DateTimeImmutable('2026-10-12 '.$arrival), $sex, $age, $withPhysician, $workAccident, $pzc);
    }

    private static function visit(int $id, string $anchor = '10:05', Sex $sex = Sex::Female, int $age = 70, ?ArrivalMode $mode = ArrivalMode::Ambulance, ?bool $workAccident = null, AnchorSource $anchorSource = AnchorSource::Admission, ?string $diagram = null): MatchingEncounter
    {
        return new MatchingEncounter($id, new \DateTimeImmutable('2026-10-12 '.$anchor), $anchorSource, $sex, $age, $mode, ArrivalRoute::fromArrivalMode($mode), $workAccident, $diagram);
    }
}
