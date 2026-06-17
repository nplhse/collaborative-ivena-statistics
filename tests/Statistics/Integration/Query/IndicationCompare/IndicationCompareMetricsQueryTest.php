<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\IndicationCompare;

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
use App\Statistics\Infrastructure\Query\IndicationCompare\IndicationCompareMetricsQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationCompareMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testCountsTwoIndicationsInOneScan(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-compare-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IndicationCompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationCompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationCompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationCompareSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationCompareDept']);
        AssignmentFactory::createOne(['name' => 'IndicationCompareAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationCompareRaw', 'code' => 912_360]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Compare A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Compare B']);

        $import = ImportFactory::createOne(['name' => 'IndicationCompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All);
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $result = self::getContainer()->get(IndicationCompareMetricsQuery::class)->fetch(
            $indicationA->getId(),
            $indicationB->getId(),
            $bounds->from,
            $bounds->toExclusive,
            $scope,
        );

        self::assertSame(4, $result->sideA->total);
        self::assertSame(2, $result->sideB->total);
        self::assertSame(4, $result->sideA->withPhysician);
        self::assertSame(4, $result->sideA->male);
        self::assertSame(2, $result->sideB->female);
    }

    public function testReturnsEmptyAggregationForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $result = self::getContainer()->get(IndicationCompareMetricsQuery::class)->fetch(
            1,
            2,
            null,
            null,
            new StatisticsScopeCriteria([]),
        );

        self::assertSame(0, $result->sideA->total);
        self::assertSame(0, $result->sideB->total);
    }
}
