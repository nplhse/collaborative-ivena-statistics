<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\HospitalPopulation;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
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
use App\Statistics\HospitalPopulation\Application\HospitalPopulationDashboardService;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HospitalPopulationDashboardServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testBuildsDashboardWithCoverageAndAllocationBasis(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'hp-dash-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Example State']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'Area Alpha', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'Area Beta', 'state' => $state]);

        $participant = HospitalFactory::createOne([
            'name' => 'Participant Hospital',
            'state' => $state,
            'dispatchArea' => $areaA,
            'size' => HospitalSize::LARGE,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'beds' => 500,
            'latitude' => 50.11,
            'longitude' => 8.68,
            'participating' => true,
        ]);
        HospitalFactory::createOne([
            'name' => 'Reference Hospital',
            'state' => $state,
            'dispatchArea' => $areaB,
            'size' => HospitalSize::SMALL,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'beds' => 100,
            'latitude' => 51.31,
            'longitude' => 9.48,
            'participating' => false,
        ]);

        SpecialityFactory::createOne(['name' => 'HpDashSpec']);
        DepartmentFactory::createOne(['name' => 'HpDashDept']);
        AssignmentFactory::createOne(['name' => 'HpDashAssign']);
        IndicationRawFactory::createOne(['name' => 'HpDashRaw', 'code' => 912_601]);

        $import = ImportFactory::createOne(['name' => 'HpDashImport', 'hospital' => $participant, 'createdBy' => $user]);
        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $participant,
            'state' => $state,
            'dispatchArea' => $areaA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 08:30:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $result = self::getContainer()->get(HospitalPopulationDashboardService::class)->build();

        self::assertSame(2, $result->overview->totalHospitals);
        self::assertSame(1, $result->overview->participants);
        self::assertSame(0.5, $result->overview->coverage);
        self::assertCount(3, $result->allocationBasis->byTier);
        self::assertCount(3, $result->allocationBasis->byLocation);
        self::assertCount(3, $result->allocationBasis->sizeByTierCrossTable->rows);
        self::assertSame(12, $result->allocationBasis->byTier[2]->totalAllocations);
        self::assertCount(2, $result->mapMarkers);
        self::assertCount(2, $result->regionalCoverage);
        self::assertCount(3, $result->bedsBoxPlotByCareLevel);
        self::assertCount(3, $result->bedsBoxPlotByLocation);
        self::assertCount(3, $result->overview->sizeByTierCrossTable->rows);
        self::assertCount(3, $result->overview->sizeByTierCrossTable->columns);
        self::assertSame(1, $result->overview->sizeByTierCrossTable->rows[2]->cells[2]->participants);
        self::assertSame(0, $result->overview->sizeByTierCrossTable->rows[0]->cells[0]->participants);
        self::assertSame(1, $result->overview->urbanityByTierCrossTable->rows[2]->cells[2]->participants);
        self::assertSame('Example State', $result->regionalCoverage[0]->stateName);
    }
}
