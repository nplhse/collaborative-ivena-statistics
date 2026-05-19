<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

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
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\Entity\ProjectionStateHospitalCount;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\Statistics\Infrastructure\Query\Overview\CountDistinctHospitalsForStateQuery;
use App\Statistics\Infrastructure\Query\Overview\GetDistinctHospitalIdsByStateQuery;
use App\Statistics\Infrastructure\Query\Overview\GetEligibleStateIdsQuery;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class OverviewMaterializedViewQueryTest extends KernelTestCase
{
    use Factories;
    use RefreshesStatisticsMaterializedViewsTrait;
    use ResetDatabase;

    public function testMaterializedViewQueriesMatchProjectionScopeAfterRefresh(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'mv-overview-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'MvOverviewState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'MvOverviewDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'MvOverviewHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'MvOverviewHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'MvOverviewSpec']);
        DepartmentFactory::createOne(['name' => 'MvOverviewDept']);
        AssignmentFactory::createOne(['name' => 'MvOverviewAssign']);
        OccasionFactory::createOne(['name' => 'MvOverviewOcc']);
        SecondaryTransportFactory::createOne(['name' => 'MvOverviewSec']);
        InfectionFactory::createOne(['name' => 'MvOverviewInf']);
        IndicationRawFactory::createOne(['name' => 'MvOverviewRaw', 'code' => 912_349]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'MvOverviewNorm']);

        $importA = ImportFactory::createOne(['name' => 'MvOverviewImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'MvOverviewImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        $allocationDefaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ];

        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importA, 'hospital' => $hospitalA]));
        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importB, 'hospital' => $hospitalB]));

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $this->refreshStatisticsMaterializedViews();

        $legacy = self::getContainer()->get(AllocationStatsProjectionScopeQuery::class);
        $stateId = $state->getId();

        self::assertSame(
            $legacy->stateIdsWithAtLeastDistinctHospitals(2),
            self::getContainer()->get(GetEligibleStateIdsQuery::class)(2),
        );
        $legacyHospitalIds = $legacy->distinctHospitalIdsForState($stateId);
        $mvHospitalIds = self::getContainer()->get(GetDistinctHospitalIdsByStateQuery::class)($stateId);
        sort($legacyHospitalIds);
        sort($mvHospitalIds);
        self::assertSame($legacyHospitalIds, $mvHospitalIds);
        self::assertSame(
            2,
            self::getContainer()->get(CountDistinctHospitalsForStateQuery::class)($stateId),
        );

        $entity = self::getContainer()->get(EntityManagerInterface::class)
            ->find(ProjectionStateHospitalCount::class, $stateId);
        self::assertInstanceOf(ProjectionStateHospitalCount::class, $entity);
        self::assertSame(2, $entity->getHospitalCount());
    }
}
