<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\MonthCoverageState;
use App\Allocation\Application\DTO\MyHospitals\MonthStatus;
use App\Allocation\Application\Hospital\MyHospitals\MonthlyCoverageClassifier;
use PHPUnit\Framework\TestCase;

final class MonthlyCoverageClassifierTest extends TestCase
{
    private const string NOW = '2026-11-12 09:30:00';

    private MonthlyCoverageClassifier $classifier;

    #[\Override]
    protected function setUp(): void
    {
        $this->classifier = new MonthlyCoverageClassifier();
    }

    public function testClassifiesEveryMonthOfTheHistory(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2026-01' => 100, '2026-03' => 120, '2026-05' => 50, '2026-06' => 110, '2026-07' => 105, '2026-08' => 90, '2026-10' => 5],
            periods: [['2026-01-01', '2026-03-31'], ['2026-05-10', '2026-08-31']],
            firstAllocationAt: '2026-01-02 08:00:00',
        );

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame([
            '2026-01 covered 100 31/31',
            '2026-02 no_allocations 0 28/28', // inside the first import's period, but no allocations
            '2026-03 covered 120 31/31',
            '2026-04 missing 0 0/30', // between covered months: possible gap
            '2026-05 partial 50 22/31', // 10 to 31 May
            '2026-06 covered 110 30/30',
            '2026-07 covered 105 31/31',
            '2026-08 covered 90 31/31',
            '2026-09 outside 0 0/30', // after the last import; allocations in October have no known period
            '2026-10 partial 5 0/31', // allocations without a known period
            '2026-11 in_progress 0 0/30',
        ], $this->describe($months));
        self::assertSame(['2026-04..2026-04 (1)'], $this->describeGaps($months));
    }

    public function testMonthsInsideAnImportWithoutAllocationsAreNoGaps(): void
    {
        // The import period runs until October, but the last allocations are from August.
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2026-07' => 30, '2026-08' => 20],
            periods: [['2026-07-01', '2026-10-31']],
        );

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame(
            ['2026-07 covered 30 31/31', '2026-08 covered 20 31/31', '2026-09 no_allocations 0 30/30', '2026-10 no_allocations 0 31/31', '2026-11 in_progress 0 0/30'],
            $this->describe($months),
        );
        self::assertSame([], $this->describeGaps($months));
    }

    public function testMonthsAfterTheLastImportHaveNoData(): void
    {
        $snapshot = SnapshotBuilder::snapshot(volumes: ['2026-07' => 30], periods: [['2026-07-01', '2026-07-31']]);

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame(
            ['2026-07 covered 30 31/31', '2026-08 outside 0 0/31', '2026-09 outside 0 0/30', '2026-10 outside 0 0/31', '2026-11 in_progress 0 0/30'],
            $this->describe($months),
        );
        self::assertSame([], $this->describeGaps($months));
    }

    public function testOverlappingImportsCountEachDayOnce(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2026-09' => 10],
            periods: [['2026-09-01', '2026-09-20'], ['2026-09-10', '2026-09-25'], ['2026-09-26', '2026-09-30']],
        );

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame('2026-09 covered 10 30/30', $this->describe($months)[0]);
    }

    public function testLeapYearFebruaryNeedsTwentyNineDays(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2024-02' => 7, '2024-03' => 9],
            periods: [['2024-02-01', '2024-02-28'], ['2024-03-01', '2024-03-31']],
        );

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame(['2024-02 partial 7 28/29', '2024-03 covered 9 31/31'], \array_slice($this->describe($months), 0, 2));
    }

    public function testCurrentMonthIsInProgressEvenWhenCovered(): void
    {
        $snapshot = SnapshotBuilder::snapshot(volumes: ['2026-11' => 40], periods: [['2026-11-01', '2026-11-30']]);

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        // Days after today do not count as covered.
        self::assertSame(['2026-11 in_progress 40 12/30'], $this->describe($months));
    }

    public function testSnapshotWithoutDataHasNoMonths(): void
    {
        self::assertSame([], $this->classifier->classify(SnapshotBuilder::snapshot(), new \DateTimeImmutable(self::NOW)));
    }

    public function testConsecutiveMissingMonthsFormOneGap(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2026-01' => 1, '2026-05' => 1],
            periods: [['2026-01-01', '2026-01-31'], ['2026-05-01', '2026-05-31']],
        );

        $months = $this->classifier->classify($snapshot, new \DateTimeImmutable(self::NOW));

        self::assertSame(['2026-02..2026-04 (3)'], $this->describeGaps($months));
    }

    public function testWindowFillsMonthsBeforeTheHistoryAsOutside(): void
    {
        $snapshot = SnapshotBuilder::snapshot(volumes: ['2026-09' => 10], periods: [['2026-09-01', '2026-09-30']]);
        $now = new \DateTimeImmutable(self::NOW);

        $window = $this->classifier->window($this->classifier->classify($snapshot, $now), new \DateTimeImmutable('2025-12-01'), $now);

        self::assertCount(12, $window);
        self::assertSame('2025-12 outside 0 0/31', $this->describe($window)[0]);
        self::assertSame('2026-09 covered 10 30/30', $this->describe($window)[9]);
        self::assertSame('2026-10 outside 0 0/31', $this->describe($window)[10]);
        self::assertSame(MonthCoverageState::InProgress, $window[11]->state);
    }

    /**
     * @param list<MonthStatus> $months
     *
     * @return list<string>
     */
    private function describe(array $months): array
    {
        return array_map(
            static fn (MonthStatus $m): string => \sprintf('%s %s %d %d/%d', $m->month->format('Y-m'), $m->state->value, $m->allocations, $m->coveredDays, $m->days),
            $months,
        );
    }

    /**
     * @param list<MonthStatus> $months
     *
     * @return list<string>
     */
    private function describeGaps(array $months): array
    {
        $gaps = [];
        foreach ($this->classifier->gaps($months) as $gap) {
            $gaps[] = \sprintf('%s..%s (%d)', $gap->firstMonth->format('Y-m'), $gap->lastMonth->format('Y-m'), $gap->months);
        }

        return $gaps;
    }
}
