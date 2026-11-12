<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Sex;

/**
 * Points per feature for one pair of allocation and visit (matcher v1, ADR 029).
 *
 * Points are base-2 logarithms of how much more often a value occurs for a true pair than for an
 * unrelated visit: +1 doubles the weight of a candidate, −1 halves it. They are set by judgement, not
 * estimated; calibration follows the match validation (#1057). Changing them changes the matcher version.
 *
 * | Feature | Points |
 * |---|---|
 * | time (matching anchor − IVENA arrival) | −5 … +15 min: +4; −20 … −6 or +16 … +30: +2; +31 … +45: 0; otherwise −2 |
 * | sex | equal: +2; male vs female: −4; one side diverse: 0 |
 * | age difference | 0: +2; 1: +1; 2: 0; more: −2 |
 * | arrival route | EMS: +1; unknown: 0; self-presenter: −2 |
 * | physician vehicle ↔ IVENA "with physician" | physician vehicle with physician: +2; physician vehicle without, or ambulance/patient transport with physician: −1; otherwise 0 |
 * | work accident ↔ IVENA work accident | both: +2; only one side: −1; neither: 0 |
 */
final readonly class FeatureScorer
{
    /**
     * @return array<string, int> points per {@see MatchFeature} value, in the order of the enum
     */
    public function score(MatchingAllocation $allocation, MatchingEncounter $encounter, int $timeDifferenceMinutes): array
    {
        return [
            MatchFeature::Time->value => $this->time($timeDifferenceMinutes),
            MatchFeature::Sex->value => $this->sex($allocation->sex, $encounter->sex),
            MatchFeature::Age->value => $this->age(abs($allocation->age - $encounter->age)),
            MatchFeature::ArrivalRoute->value => $this->arrivalRoute($encounter->arrivalRoute),
            MatchFeature::Physician->value => $this->physician($allocation->withPhysician, $encounter->arrivalMode),
            MatchFeature::WorkAccident->value => $this->workAccident($allocation->workAccident, true === $encounter->workAccident),
        ];
    }

    /**
     * Whole minutes from the IVENA arrival to the matching anchor (negative: anchor before the arrival).
     *
     * Both are local wall-clock times as stored; the difference is taken on the wall clock, independent
     * of the PHP time zone.
     */
    public static function timeDifferenceMinutes(\DateTimeImmutable $arrivalAt, \DateTimeImmutable $anchorAt): int
    {
        return self::wallClockMinute($anchorAt) - self::wallClockMinute($arrivalAt);
    }

    /**
     * Minutes since 1970 of the local wall-clock time (seconds dropped, as exchanged).
     */
    public static function wallClockMinute(\DateTimeImmutable $at): int
    {
        return intdiv(new \DateTimeImmutable($at->format('Y-m-d H:i'), new \DateTimeZone('UTC'))->getTimestamp(), 60);
    }

    private function time(int $minutes): int
    {
        return match (true) {
            $minutes >= -5 && $minutes <= 15 => 4,
            $minutes >= -20 && $minutes <= 30 => 2,
            $minutes > 30 && $minutes <= 45 => 0,
            default => -2,
        };
    }

    private function sex(string $allocationSex, Sex $encounterSex): int
    {
        $allocation = Sex::tryFrom($allocationSex);

        return match (true) {
            $allocation === $encounterSex => 2,
            null === $allocation, Sex::Diverse === $allocation, Sex::Diverse === $encounterSex => 0,
            default => -4,
        };
    }

    private function age(int $difference): int
    {
        return match ($difference) {
            0 => 2,
            1 => 1,
            2 => 0,
            default => -2,
        };
    }

    private function arrivalRoute(ArrivalRoute $route): int
    {
        return match ($route) {
            ArrivalRoute::Ems => 1,
            ArrivalRoute::Unknown => 0,
            ArrivalRoute::Self => -2,
        };
    }

    private function physician(bool $withPhysician, ?ArrivalMode $mode): int
    {
        return match ($mode) {
            ArrivalMode::PhysicianVehicle => $withPhysician ? 2 : -1,
            ArrivalMode::Ambulance, ArrivalMode::PatientTransport => $withPhysician ? -1 : 0,
            ArrivalMode::Self, ArrivalMode::Other, null => 0,
        };
    }

    private function workAccident(bool $allocation, bool $encounter): int
    {
        return match (true) {
            $allocation && $encounter => 2,
            $allocation || $encounter => -1,
            default => 0,
        };
    }
}
