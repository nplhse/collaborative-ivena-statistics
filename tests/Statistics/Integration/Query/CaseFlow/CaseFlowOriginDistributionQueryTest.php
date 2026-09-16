<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\CaseFlow;

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
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowOriginDistributionQueryTest extends KernelTestCase
{
    use Factories;

    public function testInsightPopulationFilterLimitsOriginCounts(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-origin-pop-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowOriginPopState']);
        $originArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowOriginPopArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowOriginPopHospital',
            'state' => $state,
            'dispatchArea' => $originArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        $targetSpeciality = SpecialityFactory::createOne(['name' => 'CaseFlowOriginPopSpec']);
        $otherSpeciality = SpecialityFactory::createOne(['name' => 'CaseFlowOriginPopOtherSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowOriginPopDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowOriginPopAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowOriginPopRaw', 'code' => 912_512]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowOriginPopImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $originArea,
            'speciality' => $targetSpeciality,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 08:30:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $originArea,
            'speciality' => $otherSpeciality,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 09:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $query = self::getContainer()->get(CaseFlowOriginDistributionQuery::class);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $all = $query->fetch(null, null, $scope);
        self::assertCount(1, $all);
        self::assertSame(7, $all[0]->caseCount);

        $filtered = $query->fetch(
            null,
            null,
            $scope,
            null,
            null,
            population: InsightPopulationFilter::of('speciality_id', [$targetSpeciality->getId()]),
        );
        self::assertCount(1, $filtered);
        self::assertSame(4, $filtered[0]->caseCount);

        $empty = $query->fetch(
            null,
            null,
            $scope,
            null,
            null,
            population: InsightPopulationFilter::of('speciality_id', []),
        );
        self::assertSame([], $empty);
    }
}
