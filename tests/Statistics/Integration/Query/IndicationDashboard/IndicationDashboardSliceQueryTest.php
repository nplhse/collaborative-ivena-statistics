<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\IndicationDashboard;

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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSliceQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationDashboardSliceQueryTest extends KernelTestCase
{
    use Factories;

    public function testAggregatesAllSliceDimensionsInOneQuery(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-slice-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IndicationSliceState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationSliceDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationSliceHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationSliceSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationSliceDept']);
        AssignmentFactory::createOne(['name' => 'IndicationSliceAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationSliceRaw', 'code' => 912_350]);

        $targetIndication = IndicationNormalizedFactory::createOne(['name' => 'Slice Target']);
        $otherIndication = IndicationNormalizedFactory::createOne(['name' => 'Slice Other']);

        $import = ImportFactory::createOne(['name' => 'IndicationSliceImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 85,
            'indicationNormalized' => $targetIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-01 02:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 02:30:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'age' => 40,
            'indicationNormalized' => $targetIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:20:00'),
        ]);

        AllocationFactory::createMany(7, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'indicationNormalized' => $otherIndication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 12:15:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::All,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $slice = self::getContainer()->get(IndicationDashboardSliceQuery::class)
            ->fetch($targetIndication->getId(), $bounds->from, $bounds->toExclusive, $scope);

        self::assertSame(3, $slice->genderCounts['male']);
        self::assertSame(2, $slice->genderCounts['female']);
        self::assertSame(5, array_sum(array_column($slice->monthlyRows, 'count')));
        self::assertNotEmpty($slice->ageGroupCounts);
        self::assertNotEmpty($slice->dayTimeHeatmapCells);
        self::assertNotEmpty($slice->shiftHeatmapCells);
        self::assertSame(3, $slice->transportTimeBucketCounts['30_40'] ?? 0);
        self::assertSame(2, $slice->transportTimeBucketCounts['20_30'] ?? 0);
    }

    public function testReturnsEmptySliceForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $slice = self::getContainer()->get(IndicationDashboardSliceQuery::class)
            ->fetch(1, null, null, new StatisticsScopeCriteria([]));

        self::assertSame(['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0], $slice->genderCounts);
        self::assertSame([], $slice->monthlyRows);
        self::assertSame([], $slice->ageGroupCounts);
        self::assertSame([], $slice->transportTimeBucketCounts);
        self::assertSame([], $slice->dayTimeHeatmapCells);
        self::assertSame([], $slice->shiftHeatmapCells);
    }
}
