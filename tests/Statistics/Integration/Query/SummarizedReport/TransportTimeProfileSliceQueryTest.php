<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\SummarizedReport;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
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
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\SummarizedReport\TransportTimeProfileSliceQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TransportTimeProfileSliceQueryTest extends KernelTestCase
{
    use Factories;

    public function testAggregatesBucketsAndTopNInOneQuery(): void
    {
        self::bootKernel();

        [$hospitalA, $hospitalB, $importA, $importB, $deptShort, $deptLong, $state, $dispatchArea] = $this->seed();

        AllocationFactory::createOne([
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'department' => $deptShort,
            'isWithPhysician' => false,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:05:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::AIR,
            'department' => $deptLong,
            'isWithPhysician' => true,
            'requiresResus' => true,
            'requiresCathlab' => true,
            'createdAt' => new \DateTimeImmutable('2025-06-15 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 12:10:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'department' => $deptShort,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:08:00'),
        ]);

        $unknown = AllocationFactory::createOne([
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'createdAt' => new \DateTimeImmutable('2025-06-15 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 13:20:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        self::getContainer()->get(Connection::class)->update(
            'allocation_stats_projection',
            ['transport_time_minutes' => -1],
            ['id' => $unknown->getId()],
        );

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospitalA->getId(),
            null,
            StatisticsFilterPeriod::Month,
            2025,
            6,
        );
        $slice = self::getContainer()->get(TransportTimeProfileSliceQuery::class)->fetch(
            new StatisticsScopeCriteria([$hospitalA->getId()]),
            StatisticsPeriodResolver::resolve($filter),
        );

        self::assertSame(1, $slice->unknownCount);
        self::assertSame(1, $slice->volumeByBucket['under_10'] ?? 0);
        self::assertSame(1, $slice->volumeByBucket['over_60'] ?? 0);
        self::assertSame(2, $slice->knownTotal());
        self::assertSame(3, $slice->allocationTotal());
        self::assertSame(1, $slice->genderByBucket['under_10']['2'] ?? 0);
        self::assertSame(1, $slice->urgencyByBucket['over_60']['1'] ?? 0);
        self::assertSame(1, $slice->physicianByBucket['over_60']['1'] ?? 0);
        self::assertSame(1, $slice->resusByBucket['over_60']['1'] ?? 0);
        self::assertSame(1, $slice->cathlabByBucket['over_60']['1'] ?? 0);
        self::assertSame(1, $slice->transportTypeByBucket['over_60']['2'] ?? 0);
        self::assertSame($deptShort->getId(), $slice->departmentsByBucket['under_10'][0]['id']);
        self::assertSame($deptLong->getId(), $slice->departmentsByBucket['over_60'][0]['id']);

        $otherHospital = self::getContainer()->get(TransportTimeProfileSliceQuery::class)->fetch(
            new StatisticsScopeCriteria([$hospitalB->getId()]),
            StatisticsPeriodResolver::resolve($filter),
        );
        self::assertSame(1, $otherHospital->knownTotal());
        self::assertSame(0, $otherHospital->volumeByBucket['over_60'] ?? 0);

        $emptyScope = self::getContainer()->get(TransportTimeProfileSliceQuery::class)->fetch(
            new StatisticsScopeCriteria([]),
            StatisticsPeriodResolver::resolve($filter),
        );
        self::assertSame(0, $emptyScope->allocationTotal());

        $outsidePeriod = self::getContainer()->get(TransportTimeProfileSliceQuery::class)->fetch(
            new StatisticsScopeCriteria([$hospitalA->getId()]),
            StatisticsPeriodResolver::resolve(new StatisticsFilter(
                StatisticsFilterScope::Hospital,
                $hospitalA->getId(),
                null,
                StatisticsFilterPeriod::Month,
                2025,
                5,
            )),
        );
        self::assertSame(0, $outsidePeriod->knownTotal());

        $femaleOnly = self::getContainer()->get(TransportTimeProfileSliceQuery::class)->fetch(
            new StatisticsScopeCriteria([$hospitalA->getId()]),
            StatisticsPeriodResolver::resolve($filter),
            new StatisticsDrawerFilter(gender: 2),
        );
        self::assertSame(1, $femaleOnly->knownTotal());
        self::assertSame(1, $femaleOnly->volumeByBucket['under_10'] ?? 0);
        self::assertSame(0, $femaleOnly->volumeByBucket['over_60'] ?? 0);
    }

    /**
     * @return array{0: object, 1: object, 2: object, 3: object, 4: object, 5: object, 6: object, 7: object}
     */
    private function seed(): array
    {
        $user = UserFactory::createOne(['username' => 'ttp-slice-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TtpSliceState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TtpSliceDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'TtpSliceHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'TtpSliceHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $deptShort = DepartmentFactory::createOne(['name' => 'TtpShortDept']);
        $deptLong = DepartmentFactory::createOne(['name' => 'TtpLongDept']);
        SpecialityFactory::createOne(['name' => 'TtpSliceSpec']);
        AssignmentFactory::createOne(['name' => 'TtpSliceAssign']);
        IndicationRawFactory::createOne(['name' => 'TtpSliceRaw', 'code' => 912_352]);
        IndicationNormalizedFactory::createOne(['name' => 'TtpSliceNorm']);
        $importA = ImportFactory::createOne(['name' => 'TtpSliceImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'TtpSliceImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        return [$hospitalA, $hospitalB, $importA, $importB, $deptShort, $deptLong, $state, $dispatchArea];
    }
}
