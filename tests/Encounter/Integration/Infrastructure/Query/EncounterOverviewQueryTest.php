<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Overview\EncounterOverview;
use App\Encounter\Application\Overview\EncounterOverviewFactory;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\OverviewCount;
use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Application\Overview\ProcessTimeRow;
use App\Encounter\Application\Overview\RouteLinkRow;
use App\Encounter\Application\Overview\TimeBucket;
use App\Encounter\Application\Overview\TimeGrain;
use App\Encounter\Infrastructure\Query\EncounterOverviewQuery;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\OverviewDataFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Figures of the encounter overview (#1055) on the hand-made data of OverviewDataFixtures: period
 * 10–12 October 2026 holds V1–V7, B2 (10 Oct 00:00) and B3 (12 Oct 23:59) = 9 visits; B1 (9 Oct
 * 23:59) and B4 (13 Oct 00:00) lie outside. The other hospital is never counted.
 */
#[ResetDatabase]
final class EncounterOverviewQueryTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;
    use OverviewDataFixtures;

    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $this->loadOverviewData($this->hospital, $this->encounterHospital(), $this->user());
    }

    public function testCoverageCountsVisitsImportsAndDaysOfThePeriodByTheirAnchor(): void
    {
        $coverage = $this->query()->coverage($this->hospitalId(), $this->period());

        self::assertSame(9, $coverage->visits, 'V1–V7, B2 at 00:00 of the first day and B3 at 23:59 of the last day.');
        self::assertSame(2, $coverage->imports);
        self::assertSame(3, $coverage->days);
        self::assertSame('2026-10-10 00:00', $coverage->firstAnchor?->format('Y-m-d H:i'));
        self::assertSame('2026-10-12 23:59', $coverage->lastAnchor?->format('Y-m-d H:i'));

        $allTime = $this->query()->coverage($this->hospitalId(), EncounterPeriod::allTime());
        self::assertSame(11, $allTime->visits, 'B1 and B4 just outside the period count without a period.');
        self::assertSame(5, $allTime->days);
        self::assertSame('2026-10-09 23:59', $allTime->firstAnchor?->format('Y-m-d H:i'));
        self::assertSame('2026-10-13 00:00', $allTime->lastAnchor?->format('Y-m-d H:i'));
    }

    public function testVisitsByArrivalRouteAndLinkStatus(): void
    {
        self::assertSame([
            'ems' => ['auto_accepted' => 3, 'confirmed' => 1, 'not_linked' => 1],
            'self' => ['not_linked' => 2],
            'unknown' => ['not_linked' => 2],
        ], $this->query()->visitsByRouteAndLink($this->hospitalId(), $this->period()), 'EMS: V1, V4, B3 automatic, V2 confirmed, V3 only proposed; self: V5, V6 rejected; unknown: V7 other, B2 empty.');
    }

    public function testCountsOfTriageDispositionAndCareLevel(): void
    {
        $query = $this->query();
        $hospital = $this->hospitalId();
        $period = $this->period();

        self::assertSame(['' => 1, 'blue' => 1, 'green' => 2, 'orange' => 2, 'red' => 1, 'yellow' => 2], $query->visitsByCategory($hospital, $period));
        self::assertSame(['' => 3, 'kopfschmerz' => 2, 'thoraxschmerz' => 3, 'wunden' => 1], $query->visitsByDiagram($hospital, $period));
        self::assertSame(['' => 1, 'admitted' => 3, 'died' => 1, 'home' => 2, 'left_before_completion' => 1, 'transferred_external' => 1], $query->visitsByDisposition($hospital, $period));
        self::assertSame(['' => 1, 'icu' => 1, 'monitoring' => 1, 'ward' => 1], $query->visitsByCareLevel($hospital, $period), 'Only V1, V2, V4 (admitted) and V3 (transferred, no care level).');
    }

    /**
     * Valid durations and continuous percentiles (position p × (n − 1), linear interpolation):
     * assessment 5, 10, 15, 20, 25, 30 → Q1 10 + 0.25 × 5 = 11.25, median (15 + 20) / 2 = 17.5, Q3 20 + 0.75 × 5 = 23.75;
     * physician 20, 40, 45, 60, 120 (V5 −10 negative) → 40, 45, 60;
     * discharge 0, 120, 180, 240, 1440 (V4 1560 implausible) → 120, 180, 240.
     * V6, B2 and B3 have no ED admission and are missing in all three.
     */
    public function testProcessTimesRecomputedFromTimestamps(): void
    {
        $query = $this->query();

        $assessment = $query->processTime($this->hospitalId(), $this->period(), ProcessInterval::AdmissionToAssessment);
        self::assertSame([6, 3, 0, 0], [$assessment->valid, $assessment->missing, $assessment->negative, $assessment->implausible]);
        self::assertSame([11.25, 17.5, 23.75], [$assessment->q1, $assessment->median, $assessment->q3]);

        $physician = $query->processTime($this->hospitalId(), $this->period(), ProcessInterval::AdmissionToPhysician);
        self::assertSame([5, 3, 1, 0], [$physician->valid, $physician->missing, $physician->negative, $physician->implausible]);
        self::assertSame([40.0, 45.0, 60.0], [$physician->q1, $physician->median, $physician->q3]);

        $discharge = $query->processTime($this->hospitalId(), $this->period(), ProcessInterval::AdmissionToDischarge);
        self::assertSame([5, 3, 0, 1], [$discharge->valid, $discharge->missing, $discharge->negative, $discharge->implausible], '0 and exactly 1440 minutes count, 1560 does not.');
        self::assertSame([120.0, 180.0, 240.0], [$discharge->q1, $discharge->median, $discharge->q3]);
    }

    /**
     * 12 October 2026 is a Monday. Week of 5 October: B2 (Saturday, unknown) and V1–V7 (Sunday): EMS V1–V4,
     * self V5, V6, unknown V7, B2. Week of 12 October: B3 (EMS). Months: everything in October.
     */
    public function testVisitsPerWeekAndMonthByArrivalRoute(): void
    {
        self::assertSame([
            '2026-10-05' => ['ems' => 4, 'self' => 2, 'unknown' => 2],
            '2026-10-12' => ['ems' => 1],
        ], $this->query()->visitsByBucket($this->hospitalId(), $this->period(), TimeGrain::Week));
        self::assertSame([
            '2026-10-01' => ['ems' => 5, 'self' => 2, 'unknown' => 2],
        ], $this->query()->visitsByBucket($this->hospitalId(), $this->period(), TimeGrain::Month));

        $series = $this->overview($this->period())->timeSeries;
        self::assertSame(
            ['2026-10-05 8', '2026-10-12 -'],
            array_map(static fn (TimeBucket $bucket): string => $bucket->start->format('Y-m-d').' '.($bucket->total->shownValue() ?? '-'), $series),
            '1 visit in the second week is suppressed.',
        );

        $allTime = $this->overview(EncounterPeriod::allTime())->timeSeries;
        self::assertSame(['2026-10-05', '2026-10-12'], array_map(static fn (TimeBucket $bucket): string => $bucket->start->format('Y-m-d'), $allTime), 'B1 (Friday 9 Oct) and B4 (Tuesday 13 Oct) fall into the same two weeks.');
    }

    /**
     * Admission → first assessment per category: red 5 (V1), orange 10 and 15 (V2, V3), yellow 20 (V4),
     * green 25 (V5), blue 30 (V7). Orange: P10 = 10 + 0.1 × 5 = 10.5, Q1 11.25, median 12.5, Q3 13.75,
     * P90 14.5. No category reaches 5 durations, so the box plot shows none.
     */
    public function testProcessTimesPerMtsCategory(): void
    {
        $rows = [];
        foreach ($this->query()->processTimeByCategory($this->hospitalId(), $this->period(), ProcessInterval::AdmissionToAssessment) as $row) {
            $rows[$row->category] = [$row->valid, $row->p10, $row->q1, $row->median, $row->q3, $row->p90];
        }

        self::assertSame([
            'blue' => [1, 30.0, 30.0, 30.0, 30.0, 30.0],
            'green' => [1, 25.0, 25.0, 25.0, 25.0, 25.0],
            'orange' => [2, 10.5, 11.25, 12.5, 13.75, 14.5],
            'red' => [1, 5.0, 5.0, 5.0, 5.0, 5.0],
            'yellow' => [1, 20.0, 20.0, 20.0, 20.0, 20.0],
        ], $rows);

        $boxes = $this->overview($this->period())->processBoxes['admission_to_assessment'];
        self::assertSame(['red', 'orange', 'yellow', 'green', 'blue'], array_map(static fn (\App\Encounter\Application\Overview\ProcessTimeBox $box): string => $box->category, $boxes));
        self::assertSame([true, true, true, true, true], array_map(static fn (\App\Encounter\Application\Overview\ProcessTimeBox $box): bool => $box->suppressed, $boxes));
        self::assertNull($boxes[1]->values);
    }

    public function testOverviewSuppressesSmallCellsAndStatesDenominators(): void
    {
        $overview = $this->overview($this->period());

        self::assertSame([
            'ems' => [null, null, null, null, 5],
            'self' => [0, 0, 0, null, null],
            'unknown' => [0, 0, 0, null, null],
            'total' => [null, null, null, 5, 9],
        ], $this->routeLinkTable($overview), 'Shown automatic, confirmed, linked, not linked, total: 1–4 are suppressed (null), 0 is shown.');
        self::assertFalse($overview->routeLink[1]->automatic->count->suppressed);
        self::assertEqualsWithDelta(5 * 100 / 9, $overview->routeLink[3]->notLinked->share, 0.0001, 'Not linked: 5 of 9 visits.');
        self::assertNull($overview->routeLink[0]->linked->share, 'A suppressed count has no share.');

        self::assertSame(['red' => null, 'orange' => null, 'yellow' => null, 'green' => null, 'blue' => null, '' => null], $this->shown($overview->categories));
        self::assertSame(['thoraxschmerz' => null, 'kopfschmerz' => null, 'wunden' => null, 'other' => 0, '' => null], $this->shown($overview->diagrams));
        self::assertSame(3, $overview->diagramsShown);
        self::assertSame(4, $overview->careLevelDenominator);

        $rows = array_map(static fn (ProcessTimeRow $row): array => [$row->suppressed, $row->median, $row->valid->value, $row->missing->suppressed], $overview->processTimes);
        self::assertSame([
            [false, 17.5, 6, true],
            [false, 45.0, 5, true],
            [false, 180.0, 5, true],
        ], $rows);
    }

    public function testMedianIsSuppressedBelowFiveValidDurations(): void
    {
        $overview = $this->overview(EncounterPeriod::fromQuery('2026-10-11', '2026-10-11'));

        self::assertSame(7, $overview->coverage->visits, 'Only V1–V7.');
        $physician = $overview->processTimes[1];
        self::assertSame(ProcessInterval::AdmissionToPhysician, $physician->interval);
        self::assertFalse($physician->suppressed);

        $first = $this->overview(EncounterPeriod::fromQuery('2026-10-10', '2026-10-10'));
        self::assertSame(1, $first->coverage->visits, 'Only B2 at 00:00.');
        self::assertTrue($first->processTimes[0]->suppressed);
        self::assertNull($first->processTimes[0]->median);
        self::assertSame(11, $first->allTimeCoverage->visits);
    }

    public function testOtherHospitalHasItsOwnFiguresOnly(): void
    {
        $other = $this->query()->hospitalsWithVisits();
        self::assertCount(2, $other);

        foreach (array_keys($other) as $hospitalId) {
            if ($hospitalId === $this->hospitalId()) {
                continue;
            }

            self::assertSame(['ems' => ['auto_accepted' => 6]], $this->query()->visitsByRouteAndLink($hospitalId, $this->period()));
            self::assertSame(['red' => 6], $this->query()->visitsByCategory($hospitalId, $this->period()));
        }
    }

    /**
     * @return array<string, list<int|null>>
     */
    private function routeLinkTable(EncounterOverview $overview): array
    {
        $table = [];
        foreach ($overview->routeLink as $row) {
            self::assertInstanceOf(RouteLinkRow::class, $row);
            $table[$row->route] = [
                $row->automatic->shownValue(),
                $row->confirmed->shownValue(),
                $row->linked->shownValue(),
                $row->notLinked->shownValue(),
                $row->total->shownValue(),
            ];
        }

        return $table;
    }

    /**
     * @param list<OverviewCount> $rows
     *
     * @return array<string, int|null>
     */
    private function shown(array $rows): array
    {
        $shown = [];
        foreach ($rows as $row) {
            $shown[$row->key] = $row->shownValue();
        }

        return $shown;
    }

    private function overview(EncounterPeriod $period): EncounterOverview
    {
        $factory = self::getContainer()->get(EncounterOverviewFactory::class);
        self::assertInstanceOf(EncounterOverviewFactory::class, $factory);

        return $factory->create($this->hospitalId(), $period);
    }

    private function query(): EncounterOverviewQuery
    {
        $query = self::getContainer()->get(EncounterOverviewQuery::class);
        self::assertInstanceOf(EncounterOverviewQuery::class, $query);

        return $query;
    }

    private function period(): EncounterPeriod
    {
        return EncounterPeriod::fromQuery('2026-10-10', '2026-10-12');
    }

    private function hospitalId(): int
    {
        return (int) $this->hospital->getId();
    }
}
