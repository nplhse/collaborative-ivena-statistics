<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\OwnHospitalAllocationsExportQuery;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OwnHospitalAllocationsExportQueryTest extends KernelTestCase
{
    use Factories;

    private OwnHospitalAllocationsExportQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(OwnHospitalAllocationsExportQuery::class);
    }

    public function testOwnerSeesOnlyOwnHospitalAllocations(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $ownedHospital = HospitalFactory::createOne(['owner' => $owner]);
        $foreignHospital = HospitalFactory::createOne(['owner' => $other]);
        $this->seedAllocationDependencies($ownedHospital);

        AllocationFactory::createOne([
            'hospital' => $ownedHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 10:00:00'),
            'createdAt' => new \DateTimeImmutable('2026-01-15 09:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $foreignHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 11:00:00'),
            'createdAt' => new \DateTimeImmutable('2026-01-15 09:30:00'),
        ]);

        $filter = $this->createJanuaryFilter();
        $count = $this->query->count([(int) $ownedHospital->getId()], $filter);

        self::assertSame(1, $count);
    }

    public function testArrivalAtIntervalExcludesOutsideRange(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $this->seedAllocationDependencies($hospital);

        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-01 07:59:59'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-01 08:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-01 18:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-01 18:00:01'),
        ]);

        $filter = new OwnHospitalAllocationsExportFilter(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-01'),
            timeFrom: new \DateTimeImmutable('1970-01-01 08:00:00'),
            timeTo: new \DateTimeImmutable('1970-01-01 18:00:00'),
        );

        $count = $this->query->count([(int) $hospital->getId()], $filter);

        self::assertSame(2, $count);
    }

    public function testGrantHospitalIncludedInScopeIds(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grantee-export']);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $this->seedAllocationDependencies($hospital);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Export,
            ]),
        ]);

        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-10 12:00:00'),
        ]);

        $filter = $this->createJanuaryFilter();
        $count = $this->query->count([(int) $hospital->getId()], $filter);

        self::assertSame(1, $count);
    }

    private function createJanuaryFilter(): OwnHospitalAllocationsExportFilter
    {
        return new OwnHospitalAllocationsExportFilter(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
        );
    }

    private function seedAllocationDependencies(object $hospital): void
    {
        ImportFactory::createOne(['name' => 'Export Test Import', 'hospital' => $hospital]);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication Raw']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }
}
