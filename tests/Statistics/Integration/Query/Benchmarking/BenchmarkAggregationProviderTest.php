<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Benchmarking;

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
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Benchmarking\Infrastructure\Query\BenchmarkAggregationProvider;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BenchmarkAggregationProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAggregatesPrimaryAndComparisonScopesInTwoQueries(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'benchmark-agg-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'BenchmarkAggState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'BenchmarkAggDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'BenchmarkAggHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'BenchmarkAggSpec']);
        DepartmentFactory::createOne(['name' => 'BenchmarkAggDept']);
        AssignmentFactory::createOne(['name' => 'BenchmarkAggAssign']);
        IndicationRawFactory::createOne(['name' => 'BenchmarkAggRaw', 'code' => 912_400]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Benchmark STEMI']);

        $import = ImportFactory::createOne(['name' => 'BenchmarkAggImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 82,
            'isWithPhysician' => true,
            'requiresResus' => true,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-03-15 02:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-15 02:30:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'age' => 45,
            'isWithPhysician' => false,
            'createdAt' => new \DateTimeImmutable('2024-01-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-01-10 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        /** @var BenchmarkAggregationProvider $provider */
        $provider = self::getContainer()->get(BenchmarkAggregationProvider::class);

        $result = $provider->aggregate(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-01-01 00:00:00')),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        );

        self::assertSame(3, $result->primary->total);
        self::assertSame(5, $result->comparison->total);
        self::assertSame(3, $result->primary->withPhysician);
        self::assertGreaterThan(0, \count($result->distributionRows));
    }
}
