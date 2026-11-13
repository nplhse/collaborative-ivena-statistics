<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Sex;

/**
 * Points per feature for one pair of allocation and visit (ADR 029).
 *
 * Points are base-2 logarithms of how much more often a value occurs for a true pair than for an
 * unrelated visit: +1 doubles the weight of a candidate, −1 halves it. They come from {@see MatchWeights}
 * (default: the production points, matcher v1.1; v1 was set by judgement, v1.1 adjusts two points after the
 * match validation); the match validation (#1057) replays other points. Changing the production points
 * changes the matcher version.
 *
 * | Feature | Points (v1.1; v1 where different) |
 * |---|---|
 * | time (matching anchor − IVENA arrival) | −5 … +15 min: +4; −20 … −6 or +16 … +30: +3 (v1: +2); +31 … +45: 0; otherwise −2 |
 * | sex | equal: +2; male vs female: −2 (v1: −4); one side diverse: 0 |
 * | age difference | 0: +2; 1: +1; 2: 0; more: −2 |
 * | arrival route | EMS: +1; unknown: 0; self-presenter: −2 |
 * | physician vehicle ↔ IVENA "with physician" | physician vehicle with physician: +2; physician vehicle without, or ambulance/patient transport with physician: −1; otherwise 0 |
 * | work accident ↔ IVENA work accident | both: +2; only one side: −1; neither: 0 |
 */
final readonly class FeatureScorer
{
    public function __construct(
        private MatchWeights $weights = new MatchWeights(),
    ) {
    }

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
        return $this->weights->point(match (true) {
            $minutes >= -5 && $minutes <= 15 => 'time.core',
            $minutes >= -20 && $minutes <= 30 => 'time.near',
            $minutes > 30 && $minutes <= 45 => 'time.late',
            default => 'time.outside',
        });
    }

    private function sex(string $allocationSex, Sex $encounterSex): int
    {
        $allocation = Sex::tryFrom($allocationSex);

        return $this->weights->point(match (true) {
            $allocation === $encounterSex => 'sex.equal',
            null === $allocation, Sex::Diverse === $allocation, Sex::Diverse === $encounterSex => 'sex.unknown',
            default => 'sex.different',
        });
    }

    private function age(int $difference): int
    {
        return $this->weights->point(match ($difference) {
            0, 1, 2 => 'age.'.$difference,
            default => 'age.more',
        });
    }

    private function arrivalRoute(ArrivalRoute $route): int
    {
        return $this->weights->point(match ($route) {
            ArrivalRoute::Ems => 'arrival_route.ems',
            ArrivalRoute::Unknown => 'arrival_route.unknown',
            ArrivalRoute::Self => 'arrival_route.self',
        });
    }

    private function physician(bool $withPhysician, ?ArrivalMode $mode): int
    {
        return $this->weights->point(match ($mode) {
            ArrivalMode::PhysicianVehicle => $withPhysician ? 'physician.both' : 'physician.mismatch',
            ArrivalMode::Ambulance, ArrivalMode::PatientTransport => $withPhysician ? 'physician.mismatch' : 'physician.none',
            ArrivalMode::Self, ArrivalMode::Other, null => 'physician.none',
        });
    }

    private function workAccident(bool $allocation, bool $encounter): int
    {
        return $this->weights->point(match (true) {
            $allocation && $encounter => 'work_accident.both',
            $allocation || $encounter => 'work_accident.one',
            default => 'work_accident.neither',
        });
    }
}
