<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Entity\IndicationGroup;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationGroupCompareControllerTest extends WebTestCase
{
    use Factories;

    public function testGroupCompareRendersMemberTableAndPairwiseLinks(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-compare-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'GroupCompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GroupCompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GroupCompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GroupCompareSpec']);
        DepartmentFactory::createOne(['name' => 'GroupCompareDept']);
        AssignmentFactory::createOne(['name' => 'GroupCompareAssign']);
        IndicationRawFactory::createOne(['name' => 'GroupCompareRaw', 'code' => 912_363]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Group Compare Member A', 'code' => 6001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Group Compare Member B', 'code' => 6002]);
        $group = IndicationGroupFactory::createOne(['name' => 'Compare Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indicationA->_real());
        $groupEntity->addIndication($indicationB->_real());
        $entityManager->flush();

        $import = ImportFactory::createOne(['name' => 'GroupCompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
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
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:20:00'),
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

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$group->getId().'/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-indication-group-compare-title"]', 'Compare Group');
        self::assertSelectorExists('[data-testid="stats-indication-group-compare-table"]');
        self::assertSelectorTextContains('[data-testid="stats-indication-group-compare-table"]', 'Group Compare Member A (6001)');
        self::assertSelectorTextContains('[data-testid="stats-indication-group-compare-table"]', 'Group Compare Member B (6002)');
        self::assertSelectorExists('[data-testid="stats-indication-group-pairwise-links"]');
        self::assertSelectorTextContains(
            '[data-testid="stats-indication-group-pairwise-links"]',
            'Group Compare Member A (6001) ↔ Group Compare Member B (6002)',
        );

        $compareLink = (string) $client->getCrawler()->filter('[data-testid="stats-indication-group-pairwise-links"] a')->attr('href');
        self::assertStringContainsString('indication_a='.$indicationA->getId(), $compareLink);
        self::assertStringContainsString('indication_b='.$indicationB->getId(), $compareLink);
    }

    public function testUnknownGroupReturns404(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-compare-404-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/999999/compare', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
