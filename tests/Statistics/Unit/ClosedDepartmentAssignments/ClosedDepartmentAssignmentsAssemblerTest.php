<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosedDepartmentAssignments;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsAssembler;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentExploreUrlFactory;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsCriteria;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentMetricsRow;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentSliceData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\IdentityTranslator;

final class ClosedDepartmentAssignmentsAssemblerTest extends TestCase
{
    public function testKpisHideShareWhenTotalIsZero(): void
    {
        $assembler = $this->assembler();
        $kpis = $assembler->kpis(ClosedDepartmentMetricsRow::empty());

        self::assertSame(0, $kpis->closedCount);
        self::assertSame(0, $kpis->departmentCount);
        self::assertSame(0, $kpis->totalDepartmentCount);
        self::assertNull($kpis->sharePercent);
        self::assertNull($kpis->meanTransportMinutes);
    }

    public function testDistributionRowsIncludePercentagePointDeltasVersusRegular(): void
    {
        $metrics = new ClosedDepartmentMetricsRow(
            totalCount: 100,
            closedCount: 20,
            regularCount: 80,
            closedDepartmentCount: 2,
            totalDepartmentCount: 5,
            closedSk1: 10,
            regularSk1: 8,
            closedSk2: 6,
            regularSk2: 40,
            closedSk3: 4,
            regularSk3: 32,
            closedMale: 12,
            regularMale: 24,
            closedFemale: 8,
            regularFemale: 56,
            closedOther: 0,
            regularOther: 0,
            closedWithPhysician: 8,
            regularWithPhysician: 8,
            closedResus: 4,
            regularResus: 4,
            closedCathlab: 0,
            regularCathlab: 0,
            closedCpr: 0,
            regularCpr: 0,
            closedVentilated: 0,
            regularVentilated: 0,
            closedShock: 0,
            regularShock: 0,
            closedPregnant: 0,
            regularPregnant: 0,
            closedMeanTransportMinutes: 22.0,
            regularMeanTransportMinutes: 16.0,
        );
        $assembler = $this->assembler();
        $criteria = $this->criteria();

        $gender = $assembler->gender($metrics);
        self::assertCount(2, $gender);
        self::assertSame(60.0, $gender[0]->percent);
        self::assertSame(30.0, $gender[0]->regularPercent);
        self::assertSame(30.0, $gender[0]->deltaPp);

        $urgency = $assembler->urgency($metrics, $criteria);
        self::assertCount(3, $urgency);
        self::assertSame(50.0, $urgency[0]->percent);
        self::assertSame(10.0, $urgency[0]->regularPercent);
        self::assertSame(40.0, $urgency[0]->deltaPp);

        $resources = $assembler->resources($metrics, $criteria);
        self::assertSame(20.0, $resources[0]->percent);
        self::assertSame(5.0, $resources[0]->regularPercent);
        self::assertSame(15.0, $resources[0]->deltaPp);
        self::assertSame(0.0, $resources[1]->percent);
        self::assertSame(0.0, $resources[1]->regularPercent);
        self::assertSame(0.0, $resources[1]->deltaPp);

        $clinical = $assembler->clinicalFeatures($metrics, $criteria);
        self::assertSame(40.0, $clinical[0]->percent);
        self::assertSame(10.0, $clinical[0]->regularPercent);
        self::assertSame(30.0, $clinical[0]->deltaPp);
        self::assertSame(0.0, $clinical[4]->percent);
        self::assertSame(0.0, $clinical[4]->deltaPp);
    }

    public function testHeatmapUsesTwelveTwoHourSlots(): void
    {
        $slice = new ClosedDepartmentSliceData(
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [
                ['weekday' => 1, 'twoHourSlot' => 0, 'count' => 5],
                ['weekday' => 1, 'twoHourSlot' => 1, 'count' => 1],
            ],
        );

        $heatmap = $this->assembler()->weekdayDayTimeHeatmap($slice);

        self::assertCount(12, $heatmap->columnLabels);
        self::assertCount(7, $heatmap->rowLabels);
        self::assertSame('00–02', $heatmap->columnLabels[0]);
        self::assertSame('22–24', $heatmap->columnLabels[11]);
        self::assertSame(5, $heatmap->matrix[0][0]);
        self::assertSame(1, $heatmap->matrix[0][1]);
        self::assertSame(5, $heatmap->maxCount);
    }

    public function testNamedRowsUseShareOfClosedAndExploreKey(): void
    {
        $rows = $this->assembler()->namedRows(
            [
                ['id' => 7, 'name' => 'Innere', 'closed' => 4, 'total' => 8],
            ],
            10,
            $this->criteria(),
            'speciality',
        );

        self::assertCount(1, $rows);
        self::assertSame('Innere', $rows[0]->name);
        self::assertSame(4, $rows[0]->closedCount);
        self::assertSame(40.0, $rows[0]->shareOfClosed);
        self::assertSame('/explore/allocation', $rows[0]->exploreUrl);
    }

    public function testTransportKeepsUnknownBucketAndNullMedian(): void
    {
        $metrics = new ClosedDepartmentMetricsRow(
            totalCount: 4,
            closedCount: 4,
            regularCount: 0,
            closedDepartmentCount: 1,
            totalDepartmentCount: 1,
            closedSk1: 4,
            regularSk1: 0,
            closedSk2: 0,
            regularSk2: 0,
            closedSk3: 0,
            regularSk3: 0,
            closedMale: 4,
            regularMale: 0,
            closedFemale: 0,
            regularFemale: 0,
            closedOther: 0,
            regularOther: 0,
            closedWithPhysician: 0,
            regularWithPhysician: 0,
            closedResus: 0,
            regularResus: 0,
            closedCathlab: 0,
            regularCathlab: 0,
            closedCpr: 0,
            regularCpr: 0,
            closedVentilated: 0,
            regularVentilated: 0,
            closedShock: 0,
            regularShock: 0,
            closedPregnant: 0,
            regularPregnant: 0,
            closedMeanTransportMinutes: null,
            regularMeanTransportMinutes: null,
        );
        $slice = new ClosedDepartmentSliceData(
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            ['unknown' => 2, 'under_10' => 2],
            [],
            [],
        );

        $transport = $this->assembler()->transport($metrics, $slice);

        self::assertNull($transport->closedMeanMinutes);
        $unknown = array_find($transport->closedBuckets, fn ($bucket): bool => 'statistics.distribution.transport_time_bucket.unknown' === $bucket->labelTranslationKey);

        self::assertNotNull($unknown);
        self::assertSame(2, $unknown->count);
        self::assertSame(50.0, $unknown->percent);
        self::assertCount(count($transport->closedBuckets), $transport->totalBuckets);
        self::assertSame(2, $transport->totalBuckets[array_key_last($transport->totalBuckets)]->count);
        self::assertSame(50.0, $transport->totalBuckets[array_key_last($transport->totalBuckets)]->percent);
    }

    public function testGenderAndUrgencyUseOverviewBarClasses(): void
    {
        $metrics = new ClosedDepartmentMetricsRow(
            totalCount: 10,
            closedCount: 10,
            regularCount: 0,
            closedDepartmentCount: 1,
            totalDepartmentCount: 1,
            closedSk1: 5,
            regularSk1: 0,
            closedSk2: 3,
            regularSk2: 0,
            closedSk3: 2,
            regularSk3: 0,
            closedMale: 6,
            regularMale: 0,
            closedFemale: 4,
            regularFemale: 0,
            closedOther: 0,
            regularOther: 0,
            closedWithPhysician: 0,
            regularWithPhysician: 0,
            closedResus: 0,
            regularResus: 0,
            closedCathlab: 0,
            regularCathlab: 0,
            closedCpr: 0,
            regularCpr: 0,
            closedVentilated: 0,
            regularVentilated: 0,
            closedShock: 0,
            regularShock: 0,
            closedPregnant: 0,
            regularPregnant: 0,
            closedMeanTransportMinutes: 21.0,
            regularMeanTransportMinutes: null,
        );

        $gender = $this->assembler()->gender($metrics);
        self::assertSame('bg-primary', $gender[0]->barClass);
        self::assertSame(6, $gender[0]->count);
        self::assertSame(60.0, $gender[0]->percent);
        self::assertSame('bg-pink', $gender[1]->barClass);
        self::assertSame(40.0, $gender[1]->percent);

        $urgency = $this->assembler()->urgency($metrics, $this->criteria());
        self::assertSame('bg-red', $urgency[0]->barClass);
        self::assertSame(50.0, $urgency[0]->percent);
        self::assertSame('bg-yellow', $urgency[1]->barClass);
        self::assertSame('bg-green', $urgency[2]->barClass);

        $kpis = $this->assembler()->kpis($metrics);
        self::assertSame(21.0, $kpis->meanTransportMinutes);
    }

    public function testResourcesAndClinicalFeaturesAreSplitAndAlwaysShown(): void
    {
        $metrics = new ClosedDepartmentMetricsRow(
            totalCount: 10,
            closedCount: 10,
            regularCount: 0,
            closedDepartmentCount: 1,
            totalDepartmentCount: 1,
            closedSk1: 10,
            regularSk1: 0,
            closedSk2: 0,
            regularSk2: 0,
            closedSk3: 0,
            regularSk3: 0,
            closedMale: 10,
            regularMale: 0,
            closedFemale: 0,
            regularFemale: 0,
            closedOther: 0,
            regularOther: 0,
            closedWithPhysician: 4,
            regularWithPhysician: 0,
            closedResus: 3,
            regularResus: 0,
            closedCathlab: 0,
            regularCathlab: 0,
            closedCpr: 0,
            regularCpr: 0,
            closedVentilated: 1,
            regularVentilated: 0,
            closedShock: 0,
            regularShock: 0,
            closedPregnant: 0,
            regularPregnant: 0,
            closedMeanTransportMinutes: 21.0,
            regularMeanTransportMinutes: null,
        );
        $assembler = $this->assembler();
        $criteria = $this->criteria();

        $resources = $assembler->resources($metrics, $criteria);
        self::assertCount(2, $resources);
        self::assertSame('statistics.distribution.dim.requires_resus', $resources[0]->labelTranslationKey);
        self::assertSame(3, $resources[0]->count);
        self::assertSame(30.0, $resources[0]->percent);
        self::assertSame('statistics.distribution.dim.requires_cathlab', $resources[1]->labelTranslationKey);
        self::assertSame(0, $resources[1]->count);
        self::assertSame(0.0, $resources[1]->percent);

        $clinical = $assembler->clinicalFeatures($metrics, $criteria);
        self::assertCount(5, $clinical);
        self::assertSame('statistics.distribution.dim.is_with_physician', $clinical[0]->labelTranslationKey);
        self::assertSame(4, $clinical[0]->count);
        self::assertSame(40.0, $clinical[0]->percent);
        self::assertSame('statistics.distribution.dim.is_ventilated', $clinical[2]->labelTranslationKey);
        self::assertSame(1, $clinical[2]->count);
        self::assertSame('stats.analysis.feature.is_pregnant', $clinical[4]->labelTranslationKey);
        self::assertSame(0, $clinical[4]->count);
    }

    private function assembler(): ClosedDepartmentAssignmentsAssembler
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/explore/allocation');

        return new ClosedDepartmentAssignmentsAssembler(
            new IdentityTranslator(),
            new ClosedDepartmentExploreUrlFactory($router),
        );
    }

    private function criteria(): ClosedDepartmentAssignmentsCriteria
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        return new ClosedDepartmentAssignmentsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            TimeSeriesGrain::Month,
            $filter,
            true,
        );
    }
}
