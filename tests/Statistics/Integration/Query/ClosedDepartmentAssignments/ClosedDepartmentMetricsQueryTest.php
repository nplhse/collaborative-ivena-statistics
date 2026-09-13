<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosedDepartmentAssignments;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentMetricsQuery;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentSliceQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosedDepartmentMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testPartitionsClosedAndRegularInTheSameScopeAndPeriod(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'cda-metrics-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CdaMetricsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CdaMetricsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CdaMetricsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $departmentA = DepartmentFactory::createOne(['name' => 'CdaDeptA']);
        $departmentB = DepartmentFactory::createOne(['name' => 'CdaDeptB']);
        $speciality = SpecialityFactory::createOne(['name' => 'CdaMetricsSpec']);
        $assignment = AssignmentFactory::createOne(['name' => 'CdaMetricsAssign']);
        $infection = InfectionFactory::createOne(['name' => 'CdaMetricsInfection']);
        IndicationRawFactory::createOne(['name' => 'CdaMetricsRaw', 'code' => 912_701]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'CdaIndication']);
        $occasion = OccasionFactory::createOne(['name' => 'CdaOccasion']);
        $import = ImportFactory::createOne(['name' => 'CdaMetricsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'department' => $departmentA,
            'speciality' => $speciality,
            'assignment' => $assignment,
            'infection' => $infection,
            'departmentWasClosed' => true,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'isWithPhysician' => true,
            'requiresResus' => true,
            'indicationNormalized' => $indication,
            'occasion' => $occasion,
            'createdAt' => new \DateTimeImmutable('2026-03-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 09:24:00'),
        ]);
        AllocationFactory::createMany(6, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'department' => $departmentB,
            'departmentWasClosed' => false,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'isWithPhysician' => false,
            'requiresResus' => false,
            'indicationNormalized' => $indication,
            'occasion' => $occasion,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:18:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'department' => $departmentA,
            'departmentWasClosed' => true,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2025-01-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-01-01 09:10:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Year,
            2026,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $metricsQuery = self::getContainer()->get(ClosedDepartmentMetricsQuery::class);
        $kpis = $metricsQuery->fetchKpis($bounds->from, $bounds->toExclusive, $scope);
        self::assertSame(10, $kpis->totalCount);
        self::assertSame(4, $kpis->closedCount);
        self::assertSame(1, $kpis->closedDepartmentCount);
        self::assertSame(2, $kpis->totalDepartmentCount);
        self::assertSame(0, $kpis->closedSk1);
        self::assertSame(0, $kpis->closedMale);
        self::assertEqualsWithDelta(24.0, (float) $kpis->closedMeanTransportMinutes, 0.01);

        $metrics = $metricsQuery->fetch($bounds->from, $bounds->toExclusive, $scope);

        self::assertSame(10, $metrics->totalCount);
        self::assertSame(4, $metrics->closedCount);
        self::assertSame(6, $metrics->regularCount);
        self::assertSame(1, $metrics->closedDepartmentCount);
        self::assertSame(2, $metrics->totalDepartmentCount);
        self::assertSame(4, $metrics->closedSk1);
        self::assertSame(0, $metrics->regularSk1);
        self::assertSame(4, $metrics->closedWithPhysician);
        self::assertSame(0, $metrics->regularWithPhysician);
        self::assertSame(4, $metrics->closedMale);
        self::assertSame(6, $metrics->regularMale);
        self::assertSame(0, $metrics->closedFemale);
        self::assertSame(0, $metrics->regularFemale);
        self::assertSame(0, $metrics->closedOther);
        self::assertSame(0, $metrics->regularOther);
        self::assertEqualsWithDelta(24.0, (float) $metrics->closedMeanTransportMinutes, 0.01);
        self::assertEqualsWithDelta(18.0, (float) $metrics->regularMeanTransportMinutes, 0.01);

        $sliceQuery = self::getContainer()->get(ClosedDepartmentSliceQuery::class);
        $slice = $sliceQuery->fetch($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Month);

        self::assertSame('CdaDeptA', $slice->departments[0]['name']);
        self::assertSame(4, $slice->departments[0]['closed']);
        self::assertSame(4, $slice->departments[0]['total']);
        self::assertSame('CdaMetricsSpec', $slice->specialities[0]['name']);
        self::assertSame(4, $slice->specialities[0]['closed']);
        self::assertSame('CdaIndication', $slice->indications[0]['name']);
        self::assertSame('CdaOccasion', $slice->occasions[0]['name']);
        self::assertSame('CdaMetricsAssign', $slice->assignments[0]['name']);
        self::assertSame('CdaMetricsInfection', $slice->infections[0]['name']);
        self::assertSame('CdaMetricsDispatch', $slice->dispatchAreas[0]['name']);
        self::assertNotEmpty($slice->timeSeriesRows);
        self::assertArrayNotHasKey('day', $slice->timeSeriesRows[0]);
        self::assertNotEmpty($slice->weekdayDayTimeCells);
        foreach ($slice->weekdayDayTimeCells as $cell) {
            self::assertArrayHasKey('twoHourSlot', $cell);
            self::assertGreaterThanOrEqual(0, $cell['twoHourSlot']);
            self::assertLessThan(12, $cell['twoHourSlot']);
        }

        $summary = $sliceQuery->fetchSummary($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Month);
        self::assertNotEmpty($summary->timeSeriesRows);
        self::assertNotEmpty($summary->weekdayDayTimeCells);
        self::assertSame([], $summary->departments);
        self::assertSame([], $summary->indications);
        self::assertSame([], $summary->dispatchAreas);

        $rankings = $sliceQuery->fetchRankings($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Month);
        self::assertSame('CdaDeptA', $rankings->departments[0]['name']);
        self::assertSame([], $rankings->timeSeriesRows);
        self::assertSame([], $rankings->weekdayDayTimeCells);
        self::assertSame([], $rankings->dispatchAreas);

        $departmentOnly = $sliceQuery->fetchKinds(
            $bounds->from,
            $bounds->toExclusive,
            $scope,
            ['department'],
            TimeSeriesGrain::Month,
        );
        self::assertSame('CdaDeptA', $departmentOnly->departments[0]['name']);
        self::assertSame([], $departmentOnly->specialities);
        self::assertSame([], $departmentOnly->timeSeriesRows);

        $details = $sliceQuery->fetchDetails($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Month);
        self::assertSame([], $details->dispatchAreas);
        self::assertNotEmpty($details->closedTransportBuckets);
        self::assertSame([], $details->departments);
        self::assertSame([], $details->timeSeriesRows);

        $dispatchOnly = $sliceQuery->fetchKinds(
            $bounds->from,
            $bounds->toExclusive,
            $scope,
            ['dispatch_area'],
            TimeSeriesGrain::Month,
        );
        self::assertSame('CdaMetricsDispatch', $dispatchOnly->dispatchAreas[0]['name']);
        self::assertSame([], $dispatchOnly->closedTransportBuckets);

        $daySlice = $sliceQuery->fetch($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Day);
        self::assertNotEmpty($daySlice->timeSeriesRows);
        self::assertArrayHasKey('day', $daySlice->timeSeriesRows[0]);
        self::assertContains(1, array_column($daySlice->timeSeriesRows, 'day'));
        self::assertContains(2, array_column($daySlice->timeSeriesRows, 'day'));
    }

    public function testMissingArrivalTimesAreUnknownBucketsAndExcludedFromMeanBuckets(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'cda-missing-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CdaMissingState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CdaMissingDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CdaMissingHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        DepartmentFactory::createOne(['name' => 'CdaMissingDept']);
        SpecialityFactory::createOne(['name' => 'CdaMissingSpec']);
        AssignmentFactory::createOne(['name' => 'CdaMissingAssign']);
        IndicationRawFactory::createOne(['name' => 'CdaMissingRaw', 'code' => 912_703]);
        $import = ImportFactory::createOne(['name' => 'CdaMissingImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'departmentWasClosed' => true,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-10 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-10 09:20:00'),
        ]);
        $unknownTransport = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'departmentWasClosed' => true,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-10 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-10 11:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE allocation_stats_projection SET transport_time_minutes = -1 WHERE id = :id',
            ['id' => $unknownTransport->getId()],
        );

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Year,
            2026,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $metrics = self::getContainer()->get(ClosedDepartmentMetricsQuery::class)
            ->fetch($bounds->from, $bounds->toExclusive, $scope);

        self::assertSame(4, $metrics->closedCount);
        self::assertSame(4, $metrics->closedMale);
        self::assertEqualsWithDelta(20.0, (float) $metrics->closedMeanTransportMinutes, 0.01);

        $slice = self::getContainer()->get(ClosedDepartmentSliceQuery::class)
            ->fetch($bounds->from, $bounds->toExclusive, $scope, TimeSeriesGrain::Month);

        self::assertSame(1, $slice->closedTransportBuckets['unknown'] ?? 0);
    }
}
