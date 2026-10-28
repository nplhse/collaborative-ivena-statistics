<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\DataFixtures\Generator\Application\ClosurePlanner;
use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Generator\Application\PlannedClosure;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentStructure;
use App\DataFixtures\Structure\Dto\HospitalResources;
use PHPUnit\Framework\TestCase;

/**
 * A hospital with ten offered departments: the core, nephrology, infectious diseases, general
 * neurology, the stroke unit and general psychiatry.
 */
final class ClosurePlannerTest extends TestCase
{
    private const string LAST_DAY = '2025-12-31';

    private const array OFFERED = ['cardiology', 'gastroenterology', 'pulmonology', 'general-surgery', 'trauma-surgery', 'nephrology', 'infectious-diseases', 'general-neurology', 'stroke-unit', 'general-psychiatry'];

    /**
     * With ten departments one event closes 2.425 of them on average (1 × 0.45 + 1.5 × 0.30 +
     * 4.5 × 0.15 + 8.5 × 0.10) for 6.55 hours (393.175 minutes). A share of 10 % over 30 days needs
     * −ln(0.9) × 10 × 720 = 758.6 department-hours, so 758.6 ÷ (2.425 × 6.553) = 47.74 events.
     */
    public function testTheNumberOfEventsFollowsFromTheShare(): void
    {
        $model = new DefaultStructurePatterns()->get()->closureModel();

        self::assertEqualsWithDelta(2.425, $model->expectedDepartments(10), 1e-9);
        self::assertEqualsWithDelta(393.175 / 60.0, $model->expectedHours(), 1e-9);
        self::assertEqualsWithDelta(47.74, ClosurePlanner::expectedEvents($model, 0.10, 10, 720.0), 0.01);
        self::assertSame(0.0, ClosurePlanner::expectedEvents($model, 0.0, 10, 720.0));
        // The small presets close 1 %: −ln(0.99) × 7,200 ÷ 15.89 = 4.55 events.
        self::assertEqualsWithDelta(4.55, ClosurePlanner::expectedEvents($model, 0.01, 10, 720.0), 0.01);
    }

    /**
     * Merged per department, the closed hours of a year are the share of the profile (season and
     * weekday factors average out over the year).
     */
    public function testTheClosedShareOfDepartmentHoursMatchesTheProfile(): void
    {
        foreach ([0.05, 0.10] as $share) {
            $closed = 0.0;
            foreach (['a', 'b', 'c', 'd'] as $hospital) {
                $closed += $this->closedHours($this->year($this->profile($share), $hospital));
            }

            self::assertEqualsWithDelta($share, $closed / (4 * 10 * 365 * 24), 0.012, sprintf('share %.2f', $share));
        }
    }

    public function testWithoutAShareThereAreNoClosures(): void
    {
        self::assertSame([], $this->year($this->profile(0.0), 'a'));
    }

    public function testIntervalsAreValidAndOnlyInOfferedDepartments(): void
    {
        $offered = DepartmentStructure::labels(self::OFFERED);
        $days = 0;
        foreach ($this->year($this->profile(0.10), 'a') as $closure) {
            self::assertLessThan($closure->endsAt, $closure->startsAt);
            self::assertLessThanOrEqual(24 * 60 * 60, $closure->endsAt->getTimestamp() - $closure->startsAt->getTimestamp());
            self::assertLessThanOrEqual(self::LAST_DAY.' 23:59:59', $closure->endsAt->format('Y-m-d H:i:s'), 'never after the last day');
            self::assertContains($closure->department, $offered);
            if (24 * 60 * 60 === $closure->endsAt->getTimestamp() - $closure->startsAt->getTimestamp()) {
                self::assertSame('00:00', $closure->startsAt->format('H:i'), 'a whole day starts at midnight');
                ++$days;
            }
        }
        self::assertGreaterThan(0, $days, 'some closures last a whole day');
    }

    public function testEventsHaveAllThreeTypesSizesAndCareLevels(): void
    {
        $events = $this->events($this->year($this->profile(0.10), 'a'));
        $groups = array_filter($events, static fn (array $event): bool => null !== $event['group']);
        $clusters = array_filter($events, static fn (array $event): bool => null === $event['group'] && \count($event['departments']) > 1);
        $singles = array_filter($events, static fn (array $event): bool => 1 === \count($event['departments']));

        self::assertNotSame([], $groups, 'IVENA groups');
        self::assertNotSame([], $clusters, 'exact-time clusters');
        // "single" (0.45) plus half of "few", which closes 1 or 2 of ten departments (0.30 × 0.5).
        self::assertEqualsWithDelta(0.60, \count($singles) / \count($events), 0.08, 'single closures');
        foreach ($events as $event) {
            self::assertSame($event['departments'], array_values(array_unique($event['departments'])), 'an event closes each department once');
        }
        self::assertNotSame([], array_filter($events, static fn (array $event): bool => \count($event['departments']) >= 7), 'some events close most of the hospital');

        $inpatient = array_filter($events, static fn (array $event): bool => ClosureCareLevel::INPATIENT === $event['careLevel']);
        self::assertEqualsWithDelta(0.45, \count($inpatient) / \count($events), 0.08, 'care level per event');
    }

    public function testMoreEventsStartOnMondaysThanOnSundays(): void
    {
        $weekdays = [];
        foreach (['a', 'b', 'c', 'd'] as $hospital) {
            foreach ($this->events($this->year($this->profile(0.10), $hospital)) as $event) {
                $weekday = (int) $event['startsAt']->format('N');
                $weekdays[$weekday] = ($weekdays[$weekday] ?? 0) + 1;
            }
        }

        // Factors 1.15 and 0.80 on 52 Mondays and 52 Sundays of 2025.
        self::assertGreaterThan(1.2 * $weekdays[7], $weekdays[1]);
    }

    public function testTheSameSeedGivesTheSameClosures(): void
    {
        $plan = fn (int $seed): array => array_map(
            static fn (PlannedClosure $closure): string => $closure->department.$closure->startsAt->format('c').$closure->endsAt->format('c'),
            $this->planner()->plan($seed, 'hospital-a', '2025-01', $this->profile(0.10), $this->days('2025-01'), new \DateTimeImmutable(self::LAST_DAY)),
        );

        self::assertSame($plan(740), $plan(740));
        self::assertNotSame($plan(740), $plan(741));
    }

    /**
     * @param list<PlannedClosure> $closures
     */
    private function closedHours(array $closures): float
    {
        $byDepartment = [];
        foreach ($closures as $closure) {
            $byDepartment[$closure->department][] = [$closure->startsAt->getTimestamp(), $closure->endsAt->getTimestamp()];
        }

        $seconds = 0;
        foreach ($byDepartment as $intervals) {
            usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            [$start, $end] = $intervals[0];
            foreach ($intervals as [$from, $to]) {
                if ($from > $end) {
                    $seconds += $end - $start;
                    [$start, $end] = [$from, $to];
                    continue;
                }
                $end = max($end, $to);
            }
            $seconds += $end - $start;
        }

        return $seconds / 3600.0;
    }

    /**
     * @param list<PlannedClosure> $closures
     *
     * @return list<array{departments: list<string>, group: ?string, careLevel: ClosureCareLevel, startsAt: \DateTimeImmutable}>
     */
    private function events(array $closures): array
    {
        $events = [];
        foreach ($closures as $closure) {
            $key = $closure->sourceGroupId ?? $closure->startsAt->format('c').'|'.$closure->endsAt->format('c').'|'.$closure->careLevel->value;
            $events[$key] ??= ['departments' => [], 'group' => $closure->sourceGroupId, 'careLevel' => $closure->careLevel, 'startsAt' => $closure->startsAt];
            $events[$key]['departments'][] = $closure->department;
        }

        return array_values($events);
    }

    /**
     * @return list<PlannedClosure>
     */
    private function year(HospitalProfile $profile, string $hospital): array
    {
        $closures = [];
        for ($month = 1; $month <= 12; ++$month) {
            $key = sprintf('2025-%02d', $month);
            array_push($closures, ...$this->planner()->plan(740, 'hospital-'.$hospital, $key, $profile, $this->days($key), new \DateTimeImmutable(self::LAST_DAY)));
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

    private function profile(float $share): HospitalProfile
    {
        $missing = DepartmentStructure::labels(array_values(array_diff(DepartmentStructure::all(), self::OFFERED)));

        return new HospitalProfile('urban-basic', new HospitalResources(), 0.03, $missing, closureShare: $share);
    }

    private function planner(): ClosurePlanner
    {
        return new ClosurePlanner(new RandomStreams(), new WeightedCategoricalSampler(), new DefaultStructurePatterns());
    }
}
