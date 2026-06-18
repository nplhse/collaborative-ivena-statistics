<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support\Benchmarking;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Entity\User;
use Zenstruck\Foundry\Test\Factories;

trait EligibleBenchmarkScopeTrait
{
    use Factories;
    use RefreshesStatisticsMaterializedViewsTrait;

    /**
     * @return array{state: State, dispatchArea: DispatchArea, hospitalA: Hospital, hospitalB: Hospital}
     */
    protected function seedEligibleBenchmarkScope(User $user, string $namePrefix = 'BenchmarkScope'): array
    {
        $state = StateFactory::createOne(['name' => $namePrefix.'State']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => $namePrefix.'Dispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => $namePrefix.'HospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => $namePrefix.'HospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
        ]);

        SpecialityFactory::createOne(['name' => $namePrefix.'Speciality']);
        DepartmentFactory::createOne(['name' => $namePrefix.'Department']);
        AssignmentFactory::createOne(['name' => $namePrefix.'Assignment']);
        IndicationRawFactory::createOne(['name' => $namePrefix.'Indication', 'code' => random_int(900_000, 999_999)]);

        $importA = ImportFactory::createOne(['name' => $namePrefix.'ImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => $namePrefix.'ImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 10:20:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2025-06-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-02 11:20:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $this->refreshStatisticsMaterializedViews();

        return [
            'state' => $state->_real(),
            'dispatchArea' => $dispatchArea->_real(),
            'hospitalA' => $hospitalA->_real(),
            'hospitalB' => $hospitalB->_real(),
        ];
    }
}
