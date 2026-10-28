<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\DataFixtures\Generator\Application\ClosurePlanner;
use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Generator\Application\PlannedClosure;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use App\DataFixtures\Structure\Dto\HospitalResources;
use PHPUnit\Framework\TestCase;

final class ClosurePlannerTest extends TestCase
{
    private const string LAST_DAY = '2026-09-30';

    public function testIntervalsAreValidAndLastAtMostADay(): void
    {
        foreach ($this->year($this->profile(8.0, ['Schlaganfallmedizin', 'Neurochirurgie', 'Allgemeine Pädiatrie', 'Geburtshilfe'])) as $closure) {
            self::assertLessThan($closure->endsAt, $closure->startsAt);
            self::assertLessThanOrEqual(24 * 60 * 60, $closure->endsAt->getTimestamp() - $closure->startsAt->getTimestamp());
            self::assertLessThanOrEqual(self::LAST_DAY.' 23:59:59', $closure->endsAt->format('Y-m-d H:i:s'), 'never after the last day');
            self::assertContains($closure->department, MedicalSpecialities::unitNames());
            self::assertNotContains($closure->department, ['Schlaganfallmedizin', 'Neurochirurgie', 'Allgemeine Pädiatrie', 'Geburtshilfe'], 'only departments the hospital offers');
        }
    }

    public function testAllThreeEventTypesOccur(): void
    {
        $groups = [];
        $clusters = [];
        $singles = 0;
        $closures = $this->year($this->profile(8.0));
        self::assertContains('Schlaganfallmedizin', array_map(static fn (PlannedClosure $closure): string => $closure->department, $closures), 'a hospital with a stroke unit closes it sometimes');
        foreach ($closures as $closure) {
            if (null !== $closure->sourceGroupId) {
                $groups[$closure->sourceGroupId][] = $closure->department;
                continue;
            }
            $clusters[$closure->startsAt->format('c').'|'.$closure->endsAt->format('c')][] = $closure->department;
        }
        foreach ($clusters as $key => $departments) {
            if (1 === \count($departments)) {
                ++$singles;
                unset($clusters[$key]);
            }
        }

        self::assertNotSame([], $groups, 'IVENA groups');
        foreach ($groups as $departments) {
            self::assertGreaterThanOrEqual(2, \count($departments));
            self::assertSame($departments, array_values(array_unique($departments)), 'a group closes each department once');
        }
        self::assertNotSame([], $clusters, 'exact-time clusters');
        self::assertGreaterThan(0, $singles, 'single closures');
    }

    public function testTheSameSeedGivesTheSameClosures(): void
    {
        $plan = fn (int $seed): array => array_map(
            static fn (PlannedClosure $closure): string => $closure->department.$closure->startsAt->format('c').$closure->endsAt->format('c'),
            $this->planner()->plan($seed, 'hospital-a', '2026-01', $this->profile(5.0), $this->days('2026-01'), new \DateTimeImmutable(self::LAST_DAY)),
        );

        self::assertSame($plan(740), $plan(740));
        self::assertNotSame($plan(740), $plan(741));
    }

    public function testLargerHospitalsCloseMoreOften(): void
    {
        self::assertGreaterThan(\count($this->year($this->profile(3.0))), \count($this->year($this->profile(8.0))));
    }

    /**
     * @return list<PlannedClosure>
     */
    private function year(HospitalProfile $profile): array
    {
        $closures = [];
        foreach (['a', 'b', 'c', 'd'] as $hospital) {
            for ($month = 1; $month <= 9; ++$month) {
                $key = sprintf('2026-%02d', $month);
                array_push($closures, ...$this->planner()->plan(740, 'hospital-'.$hospital, $key, $profile, $this->days($key), new \DateTimeImmutable(self::LAST_DAY)));
            }
        }

        return $closures;
    }

    /**
     * @return list<string>
     */
    private function days(string $month): array
    {
        $days = [];
        $first = new \DateTimeImmutable($month.'-01');
        for ($day = $first; $day->format('Y-m') === $month; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @param list<string> $missingDepartments
     */
    private function profile(float $eventsPerMonth, array $missingDepartments = []): HospitalProfile
    {
        return new HospitalProfile('urban-basic', new HospitalResources(), 0.03, $missingDepartments, $eventsPerMonth);
    }

    private function planner(): ClosurePlanner
    {
        return new ClosurePlanner(new RandomStreams(), new WeightedCategoricalSampler());
    }
}
