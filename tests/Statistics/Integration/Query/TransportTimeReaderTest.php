<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Query\TransportTimeReader;
use App\Statistics\Infrastructure\Util\Period;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TransportTimeReaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testFetchCoreBucketsReturnsEmptyStructureWhenNoAllocationsMatch(): void
    {
        self::bootKernel();
        $reader = self::getContainer()->get(TransportTimeReader::class);

        // ALL vermeidet period_*-DB-Funktionen (in Test-DB ggf. nicht vorhanden)
        $scope = new Scope('public', 'all', Period::ALL, Period::ALL_ANCHOR_DATE);
        $result = $reader->fetchCoreBuckets($scope);

        foreach ($result['payload']['total'] as $row) {
            self::assertSame(0, $row['n']);
            self::assertSame(0.0, $row['share']);
        }

        self::assertNull($result['mean']);
    }

    public function testFetchCoreBucketsPlacesRowInExpectedTimeBucket(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'tt-reader-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TTState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TTDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TTHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'TTImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => 'TTSpeciality']);
        DepartmentFactory::createOne(['name' => 'TTDepartment']);
        $assignment = AssignmentFactory::createOne(['name' => 'TTAssignment']);
        OccasionFactory::createOne(['name' => 'TTOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'TTSecondary']);
        InfectionFactory::createOne(['name' => 'TTInfection']);
        IndicationRawFactory::createOne(['name' => 'TTRaw', 'code' => 555_001]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'TTNorm']);

        $createdAt = new \DateTimeImmutable('2025-06-15 10:00:00');
        $arrivalAt = new \DateTimeImmutable('2025-06-15 10:07:00');

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => $createdAt,
            'arrivalAt' => $arrivalAt,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'assignment' => $assignment,
            'occasion' => OccasionFactory::random(),
            'infection' => InfectionFactory::random(),
            'indicationRaw' => IndicationRawFactory::random(),
            'indicationNormalized' => $indicationNormalized,
            'isWithPhysician' => true,
            'requiresResus' => false,
            'requiresCathlab' => false,
        ]);

        $reader = self::getContainer()->get(TransportTimeReader::class);
        $scope = new Scope('public', 'all', Period::ALL, Period::ALL_ANCHOR_DATE);
        $result = $reader->fetchCoreBuckets($scope);

        $totalByBucket = [];
        foreach ($result['payload']['total'] as $row) {
            $totalByBucket[$row['key']] = $row['n'];
        }

        self::assertSame(1, $totalByBucket['<10'] ?? 0);
        self::assertNotNull($result['mean']);
        self::assertEqualsWithDelta(7.0, (float) $result['mean'], 0.01);
    }

    public function testFetchDimensionBucketsWithLimitKeepsOnlyTopDimensionsByVolume(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'tt-dim-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TTDimState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TTDimDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TTDimHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'TTDimImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => 'TTDimSpec']);
        DepartmentFactory::createOne(['name' => 'TTDimDept']);
        $heavyAssignment = AssignmentFactory::createOne(['name' => 'TTDimHeavy']);
        $lightAssignment = AssignmentFactory::createOne(['name' => 'TTDimLight']);
        OccasionFactory::createOne(['name' => 'TTDimOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'TTDimSecondary']);
        InfectionFactory::createOne(['name' => 'TTDimInfection']);
        IndicationRawFactory::createOne(['name' => 'TTDimRaw', 'code' => 555_002]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'TTDimNorm']);

        $base = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => new \DateTimeImmutable('2025-07-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-07-01 08:05:00'),
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::AIR,
            'occasion' => OccasionFactory::random(),
            'infection' => InfectionFactory::random(),
            'indicationRaw' => IndicationRawFactory::random(),
            'indicationNormalized' => $indicationNormalized,
        ];

        AllocationFactory::createMany(3, array_merge($base, ['assignment' => $heavyAssignment]));
        AllocationFactory::createOne(array_merge($base, ['assignment' => $lightAssignment]));

        $reader = self::getContainer()->get(TransportTimeReader::class);
        $scope = new Scope('public', 'all', Period::ALL, Period::ALL_ANCHOR_DATE);
        $rows = $reader->fetchDimensionBuckets($scope, 'assignment', 1);

        $dimIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['dim_id'], $rows)));
        self::assertCount(1, $dimIds);
        self::assertSame((int) $heavyAssignment->getId(), $dimIds[0]);
    }
}
