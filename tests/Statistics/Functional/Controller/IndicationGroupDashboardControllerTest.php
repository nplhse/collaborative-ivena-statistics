<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationGroupDashboardControllerTest extends WebTestCase
{
    use Factories;

    public function testGroupDashboardRendersAggregatedData(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'GroupState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GroupDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GroupHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GroupSpec']);
        DepartmentFactory::createOne(['name' => 'GroupDept']);
        AssignmentFactory::createOne(['name' => 'GroupAssign']);
        IndicationRawFactory::createOne(['name' => 'GroupRaw', 'code' => 912_362]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Group Member A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Group Member B']);
        $group = IndicationGroupFactory::createOne(['name' => 'Cardiology Group', 'createdBy' => $user]);
        $group->_real()->addIndication($indicationA->_real());
        $group->_real()->addIndication($indicationB->_real());
        $group->_save();

        $import = ImportFactory::createOne(['name' => 'GroupImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createOne([
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

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$group->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-indication-group-heading-title"]', 'Cardiology Group');
        self::assertSelectorExists('[data-testid="stats-indication-group-members"]');
    }
}
