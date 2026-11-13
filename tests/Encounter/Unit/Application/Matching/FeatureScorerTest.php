<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\FeatureScorer;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\Sex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Points of each feature of matcher v1 (ADR 029), one row per rule of the table in FeatureScorer.
 */
final class FeatureScorerTest extends TestCase
{
    use MatchingTestData;

    public function testATypicalPairScoresNinePointsAndNoExcludedVariableCounts(): void
    {
        $points = new FeatureScorer()->score(self::allocation(1), self::visit(1), 5);

        self::assertSame(['time' => 4, 'sex' => 2, 'age' => 2, 'arrival_route' => 1, 'physician' => 0, 'work_accident' => 0], $points);
        self::assertSame(9, array_sum($points));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function timeDifferences(): iterable
    {
        yield '21 min before' => [-21, -2];
        yield '20 min before' => [-20, 3];
        yield '6 min before' => [-6, 3];
        yield '5 min before' => [-5, 4];
        yield 'at arrival' => [0, 4];
        yield '15 min after' => [15, 4];
        yield '16 min after' => [16, 3];
        yield '30 min after' => [30, 3];
        yield '31 min after' => [31, 0];
        yield '45 min after' => [45, 0];
        yield '46 min after' => [46, -2];
        yield '90 min after' => [90, -2];
    }

    #[DataProvider('timeDifferences')]
    public function testTimePoints(int $minutes, int $expected): void
    {
        self::assertSame($expected, new FeatureScorer()->score(self::allocation(1), self::visit(1), $minutes)['time']);
    }

    public function testTimeDifferenceIsTakenOnTheWallClock(): void
    {
        self::assertSame(-7, FeatureScorer::timeDifferenceMinutes(new \DateTimeImmutable('2026-10-12 10:00'), new \DateTimeImmutable('2026-10-12 09:53')));
        self::assertSame(12, FeatureScorer::timeDifferenceMinutes(new \DateTimeImmutable('2026-10-12 23:55'), new \DateTimeImmutable('2026-10-13 00:07')));
        // Night of the clock change (25 October 2026): 02:50 → 03:10 is 20 minutes on the wall clock.
        self::assertSame(20, FeatureScorer::timeDifferenceMinutes(new \DateTimeImmutable('2026-10-25 02:50', new \DateTimeZone('Europe/Berlin')), new \DateTimeImmutable('2026-10-25 03:10', new \DateTimeZone('Europe/Berlin'))));
    }

    /**
     * @return iterable<string, array{string, Sex, int}>
     */
    public static function sexes(): iterable
    {
        yield 'female both' => ['F', Sex::Female, 2];
        yield 'male both' => ['M', Sex::Male, 2];
        yield 'diverse both' => ['X', Sex::Diverse, 2];
        yield 'male vs female' => ['M', Sex::Female, -2];
        yield 'female vs male' => ['F', Sex::Male, -2];
        yield 'IVENA diverse' => ['X', Sex::Male, 0];
        yield 'visit diverse' => ['F', Sex::Diverse, 0];
    }

    #[DataProvider('sexes')]
    public function testSexPoints(string $allocationSex, Sex $visitSex, int $expected): void
    {
        self::assertSame($expected, new FeatureScorer()->score(self::allocation(1, sex: $allocationSex), self::visit(1, sex: $visitSex), 0)['sex']);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function ages(): iterable
    {
        yield 'same' => [70, 2];
        yield 'one older' => [71, 1];
        yield 'one younger' => [69, 1];
        yield 'two' => [72, 0];
        yield 'three' => [67, -2];
        yield 'five' => [75, -2];
    }

    #[DataProvider('ages')]
    public function testAgePoints(int $visitAge, int $expected): void
    {
        self::assertSame($expected, new FeatureScorer()->score(self::allocation(1, age: 70), self::visit(1, age: $visitAge), 0)['age']);
    }

    /**
     * @return iterable<string, array{?ArrivalMode, bool, int, int}>
     */
    public static function arrivals(): iterable
    {
        // mode, IVENA with physician, route points, physician points
        yield 'ambulance' => [ArrivalMode::Ambulance, false, 1, 0];
        yield 'ambulance, IVENA with physician' => [ArrivalMode::Ambulance, true, 1, -1];
        yield 'patient transport' => [ArrivalMode::PatientTransport, false, 1, 0];
        yield 'physician vehicle with physician' => [ArrivalMode::PhysicianVehicle, true, 1, 2];
        yield 'physician vehicle without physician' => [ArrivalMode::PhysicianVehicle, false, 1, -1];
        yield 'self-presenter' => [ArrivalMode::Self, false, -2, 0];
        yield 'self-presenter, IVENA with physician' => [ArrivalMode::Self, true, -2, 0];
        yield 'other' => [ArrivalMode::Other, false, 0, 0];
        yield 'not documented' => [null, true, 0, 0];
    }

    #[DataProvider('arrivals')]
    public function testArrivalRouteAndPhysicianPoints(?ArrivalMode $mode, bool $withPhysician, int $route, int $physician): void
    {
        $points = new FeatureScorer()->score(self::allocation(1, withPhysician: $withPhysician), self::visit(1, mode: $mode), 0);

        self::assertSame($route, $points['arrival_route']);
        self::assertSame($physician, $points['physician']);
    }

    /**
     * @return iterable<string, array{bool, ?bool, int}>
     */
    public static function workAccidents(): iterable
    {
        yield 'both' => [true, true, 2];
        yield 'only IVENA' => [true, null, -1];
        yield 'only the visit' => [false, true, -1];
        yield 'neither' => [false, null, 0];
    }

    #[DataProvider('workAccidents')]
    public function testWorkAccidentPoints(bool $allocation, ?bool $visit, int $expected): void
    {
        self::assertSame($expected, new FeatureScorer()->score(self::allocation(1, workAccident: $allocation), self::visit(1, workAccident: $visit), 0)['work_accident']);
    }
}
