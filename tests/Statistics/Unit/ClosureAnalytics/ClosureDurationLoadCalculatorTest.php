<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationDistribution;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationLoadCalculator;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationInterval;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureObservedSegment;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use PHPUnit\Framework\TestCase;

final class ClosureDurationLoadCalculatorTest extends TestCase
{
    public function testOverlappingAndAdjacentIntervalsFormOnePhaseWithoutAPause(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 100),
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 50, 150),
            $this->interval(1, 11, 'B', 'capacity', 'event-2', 150, 200),
        ], [
            $this->observed(1, 0, 300),
        ]);

        self::assertSame(2, $load->eventCount);
        self::assertSame(150, $load->maximumSeconds);
        self::assertSame(1, $load->phaseCount);
        self::assertSame(200, $load->longestPhaseSeconds);
        self::assertFalse($load->pausesComputable);
        self::assertSame(300, $load->evaluableSeconds);
        self::assertSame(100, $load->noneSeconds);
        self::assertSame(200, $load->atLeastOneSeconds());
        self::assertSame($load->evaluableSeconds, $load->noneSeconds + $load->singleDepartmentSeconds + $load->multipleDepartmentsSeconds);
    }

    public function testAGapInsideCoverageIsAPauseAndEdgesAreNot(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 100, 200),
            $this->interval(1, 10, 'A', 'capacity', 'event-2', 260, 300),
        ], [
            $this->observed(1, 0, 400),
        ]);

        self::assertSame(2, $load->phaseCount);
        self::assertTrue($load->pausesComputable);
        self::assertEqualsWithDelta(60.0, $load->medianPauseSeconds, 0.0001);
        self::assertSame(60, $load->minimumPauseSeconds);
        self::assertSame(60, $load->maximumPauseSeconds);
        self::assertSame(140, $load->atLeastOneSeconds());
        self::assertSame(260, $load->noneSeconds);
        self::assertSame(400, $load->noneSeconds + $load->atLeastOneSeconds());
    }

    public function testRepeatedRowsOfOneDepartmentCountOnce(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 100),
            $this->interval(1, 10, 'A', 'technical', 'event-1', 0, 100),
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 80, 120),
        ], [
            $this->observed(1, 0, 120),
        ]);

        self::assertSame(1, $load->eventCount);
        self::assertEqualsWithDelta(120.0, $load->medianSeconds, 0.0001);
        self::assertSame(120, $load->singleDepartmentSeconds);
        self::assertSame(0, $load->multipleDepartmentsSeconds);
        self::assertCount(1, $load->specialities);
        self::assertSame(1, $load->specialities[0]->count);
        self::assertSame(120, $load->specialities[0]->maximumSeconds);
        self::assertFalse($load->specialities[0]->showBox);
    }

    public function testDistinctDepartmentsClosedTogetherAreMultiple(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 100),
            $this->interval(1, 11, 'B', 'capacity', 'event-1', 40, 100),
        ], [
            $this->observed(1, 0, 100),
        ]);

        self::assertSame(1, $load->eventCount);
        self::assertSame(100, $load->maximumSeconds);
        self::assertSame(40, $load->singleDepartmentSeconds);
        self::assertSame(60, $load->multipleDepartmentsSeconds);
        self::assertSame(0, $load->noneSeconds);
        self::assertSame(100, $load->singleDepartmentSeconds + $load->multipleDepartmentsSeconds + $load->noneSeconds);
        self::assertCount(2, $load->specialities);
        self::assertSame(1, $load->specialities[0]->count);
        self::assertSame(1, $load->specialities[1]->count);
    }

    public function testNoClosuresOnePhaseAndSeveralPhases(): void
    {
        $empty = $this->calculator()->calculate([], [$this->observed(1, 0, 500)]);
        self::assertSame(0, $empty->eventCount);
        self::assertNull($empty->medianSeconds);
        self::assertSame(0, $empty->phaseCount);
        self::assertFalse($empty->pausesComputable);
        self::assertSame(500, $empty->noneSeconds);
        self::assertSame(0, $empty->atLeastOneSeconds());

        $onePhase = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', null, 'event-1', 0, 500),
        ], [$this->observed(1, 0, 500)]);
        self::assertSame(1, $onePhase->phaseCount);
        self::assertFalse($onePhase->pausesComputable);
        self::assertNull($onePhase->medianPauseSeconds);
        self::assertSame('', $onePhase->reasons[0]->key);

        $several = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 10),
            $this->interval(1, 10, 'A', 'capacity', 'event-2', 30, 40),
            $this->interval(1, 10, 'A', 'capacity', 'event-3', 70, 90),
        ], [$this->observed(1, 0, 100)]);
        self::assertSame(3, $several->phaseCount);
        self::assertTrue($several->pausesComputable);
        self::assertSame(20, $several->minimumPauseSeconds);
        self::assertSame(30, $several->maximumPauseSeconds);
        self::assertEqualsWithDelta(25.0, $several->medianPauseSeconds, 0.0001);
    }

    public function testHospitalsAreNotMerged(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 100),
            $this->interval(2, 10, 'A', 'capacity', 'event-2', 50, 200),
        ], [
            $this->observed(1, 0, 100),
            $this->observed(2, 50, 200),
        ]);

        self::assertSame(2, $load->phaseCount);
        self::assertSame(150, $load->longestPhaseSeconds);
        self::assertFalse($load->pausesComputable);
        self::assertSame(250, $load->evaluableSeconds);
        self::assertSame(0, $load->noneSeconds);
        self::assertSame(250, $load->singleDepartmentSeconds);
    }

    public function testPausesStayInsideEachHospitalAndUnobservedGapsDoNotCount(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'h1-a', 0, 10),
            $this->interval(1, 10, 'A', 'capacity', 'h1-b', 30, 40),
            $this->interval(2, 10, 'A', 'capacity', 'h2-a', 0, 10),
            $this->interval(2, 10, 'A', 'capacity', 'h2-b', 15, 40),
            $this->interval(3, 10, 'A', 'capacity', 'h3-a', 0, 10),
            $this->interval(3, 10, 'A', 'capacity', 'h3-b', 100, 110),
        ], [
            $this->observed(1, 0, 40),
            $this->observed(2, 0, 40),
            $this->observed(3, 0, 10),
            $this->observed(3, 100, 110),
        ]);

        self::assertSame(6, $load->phaseCount);
        self::assertSame(25, $load->longestPhaseSeconds);
        self::assertTrue($load->pausesComputable);
        self::assertSame(5, $load->minimumPauseSeconds);
        self::assertSame(20, $load->maximumPauseSeconds);
        self::assertEqualsWithDelta(12.5, $load->medianPauseSeconds, 0.0001);
        self::assertSame(100, $load->evaluableSeconds);
        self::assertSame(25, $load->noneSeconds);
        self::assertSame(100, $load->noneSeconds + $load->singleDepartmentSeconds + $load->multipleDepartmentsSeconds);
    }

    public function testMultipleReasonsCountOnceInsideEachGroup(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 100),
            $this->interval(1, 11, 'B', 'technical', 'event-1', 0, 80),
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 50, 90),
            $this->interval(1, 10, 'A', 'capacity', 'event-2', 200, 250),
        ], [
            $this->observed(1, 0, 250),
        ]);

        $reasons = [];
        foreach ($load->reasons as $group) {
            $reasons[$group->key] = $group;
        }
        self::assertSame(2, $reasons['capacity']->count);
        self::assertSame(100, $reasons['capacity']->maximumSeconds);
        self::assertSame(1, $reasons['technical']->count);
        self::assertSame(80, $reasons['technical']->maximumSeconds);
        self::assertSame(2, $load->eventCount);
        self::assertSame(3, array_sum(array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup $group): int => $group->count, $load->reasons)));
    }

    public function testDepartmentsOfOneSpecialityFormOneDuration(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'Dept A', 'capacity', 'event-1', 0, 100, 5, 'Internal'),
            $this->interval(1, 11, 'Dept B', 'capacity', 'event-1', 40, 180, 5, 'Internal'),
        ], [
            $this->observed(1, 0, 180),
        ]);

        self::assertSame(120, $load->singleDepartmentSeconds);
        self::assertSame(60, $load->multipleDepartmentsSeconds);
        self::assertCount(1, $load->specialities);
        self::assertSame('Internal', $load->specialities[0]->label);
        self::assertSame(1, $load->specialities[0]->count);
        self::assertSame(180, $load->specialities[0]->maximumSeconds);
    }

    public function testSharesIgnoreTimeOutsideCoverage(): void
    {
        $load = $this->calculator()->calculate([
            $this->interval(1, 10, 'A', 'capacity', 'event-1', 0, 1800),
        ], [
            $this->observed(1, 0, 3600),
            $this->observed(1, 7200, 10800),
        ]);

        self::assertSame(7200, $load->evaluableSeconds);
        self::assertSame(1800, $load->singleDepartmentSeconds);
        self::assertSame(5400, $load->noneSeconds);
        self::assertSame(0, $load->multipleDepartmentsSeconds);
        self::assertSame(100.0, $load->sharePercent($load->evaluableSeconds));
        self::assertTrue($load->hasEvaluableTime());
        self::assertSame(21_600, $load->normalizedSeconds($load->singleDepartmentSeconds));
        self::assertSame(30, $load->minutes(1_800));
    }

    public function testTopTenUsesCountThenNameAndSortsTheChartByMedian(): void
    {
        $intervals = [];
        $observed = [$this->observed(1, 0, 10_000)];
        for ($index = 1; $index <= 9; ++$index) {
            $intervals = [...$intervals, ...$this->repeatedDepartment($index, 'Dept '.$index, 3, 10)];
        }
        $intervals = [...$intervals, ...$this->repeatedDepartment(20, 'Beta', 2, 10)];
        $intervals = [...$intervals, ...$this->repeatedDepartment(21, 'Alpha', 2, 100)];

        $load = $this->calculator()->calculate($intervals, $observed);

        self::assertTrue($load->specialitiesTruncated);
        self::assertCount(10, $load->specialities);
        $keys = array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup $group): string => $group->key, $load->specialities);
        self::assertContains('21', $keys);
        self::assertNotContains('20', $keys);
        self::assertSame('Alpha', $load->specialities[0]->label);
        self::assertEqualsWithDelta(100.0, $load->specialities[0]->medianSeconds, 0.0001);
        self::assertFalse($load->reasonsTruncated);
    }

    private function calculator(): ClosureDurationLoadCalculator
    {
        $statistics = new DescriptiveStatisticsCalculator();

        return new ClosureDurationLoadCalculator(new ClosureDurationDistribution($statistics), $statistics);
    }

    /**
     * @return list<ClosureDurationInterval>
     */
    private function repeatedDepartment(int $departmentId, string $name, int $events, int $seconds): array
    {
        $intervals = [];
        for ($event = 1; $event <= $events; ++$event) {
            $start = ($departmentId * 1000) + ($event * 100);
            $intervals[] = $this->interval(1, $departmentId, $name, 'capacity', 'dept-'.$departmentId.'-'.$event, $start, $start + $seconds);
        }

        return $intervals;
    }

    private function interval(
        int $hospitalId,
        int $departmentId,
        string $name,
        ?string $reason,
        string $eventKey,
        int $start,
        int $end,
        ?int $specialityId = null,
        ?string $specialityName = null,
    ): ClosureDurationInterval {
        return new ClosureDurationInterval(
            $hospitalId,
            $departmentId,
            $name,
            $reason,
            $eventKey,
            new \DateTimeImmutable('@'.$start),
            new \DateTimeImmutable('@'.$end),
            $specialityId ?? $departmentId,
            $specialityName ?? $name,
        );
    }

    private function observed(int $hospitalId, int $start, int $end): ClosureObservedSegment
    {
        return new ClosureObservedSegment(
            $hospitalId,
            new \DateTimeImmutable('@'.$start),
            new \DateTimeImmutable('@'.$end),
        );
    }
}
