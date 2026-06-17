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
final class IndicationGroupDashboardControllerTest extends WebTestCase
{
    use Factories;

    public function testGroupDashboardRendersAggregatedData(): void
    {
        $client = self::createClient();
        $fixture = $this->seedGroupDashboardFixture($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$fixture['groupId'], [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-indication-group-heading-title"]', 'Cardiology Group');
        self::assertSelectorExists('[data-testid="stats-indication-group-members"]');
        self::assertSelectorExists('[data-testid="stats-indication-group-picker-input"]');
        self::assertSelectorExists('[data-testid="stats-indication-group-picker-submit"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-launch-button"]');
        self::assertSelectorExists('[data-testid="stats-indication-group-compare-launch-modal"]');
        self::assertSelectorExists('[data-testid="stats-indication-group-compare-launch-presets"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-group-members-show-more"]');
        self::assertSelectorExists('[data-testid="stats-indication-group-picker-card"] .col-md-6');

        $crawler = $client->getCrawler();
        self::assertStringContainsString(
            'Cardiology Group',
            (string) $crawler->filter('[data-testid="stats-indication-group-picker-input"]')->attr('value'),
        );

        $memberLinks = $crawler->filter('[data-testid="stats-indication-group-members"] a.text-reset');
        self::assertGreaterThanOrEqual(2, $memberLinks->count());
        self::assertStringContainsString(
            '/statistics/indication/'.$fixture['indicationAId'],
            (string) $memberLinks->first()->attr('href'),
        );
        self::assertStringContainsString('Group Member A', $memberLinks->first()->text());
        self::assertStringContainsString('2', $crawler->filter('[data-testid="stats-indication-group-members"]')->text());
        self::assertStringContainsString('66,7%', $crawler->filter('[data-testid="stats-indication-group-members"]')->text());

        self::assertStringContainsString(
            'Group Member A',
            (string) $crawler->filter('#stats-indication-group-compare-launch-a')->attr('value'),
        );
        self::assertStringContainsString(
            'Group Member B',
            (string) $crawler->filter('#stats-indication-group-compare-launch-b')->attr('value'),
        );
    }

    public function testGroupMembersListShowsExpandControlWhenMoreThanThreeMembers(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-expand-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'GroupExpandState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GroupExpandDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GroupExpandHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GroupExpandSpec']);
        DepartmentFactory::createOne(['name' => 'GroupExpandDept']);
        AssignmentFactory::createOne(['name' => 'GroupExpandAssign']);
        IndicationRawFactory::createOne(['name' => 'GroupExpandRaw', 'code' => 912_364]);

        $indications = IndicationNormalizedFactory::createMany(4, static fn (int $i): array => [
            'name' => 'Expand Member '.$i,
        ]);
        $group = IndicationGroupFactory::createOne(['name' => 'Expand Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        foreach ($indications as $indication) {
            $groupEntity->addIndication($indication->_real());
        }
        $entityManager->flush();

        $import = ImportFactory::createOne(['name' => 'GroupExpandImport', 'hospital' => $hospital, 'createdBy' => $user]);
        foreach ($indications as $index => $indication) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'indicationNormalized' => $indication,
                'createdAt' => new \DateTimeImmutable(sprintf('2026-05-%02d 10:00:00', $index + 1)),
                'arrivalAt' => new \DateTimeImmutable(sprintf('2026-05-%02d 10:20:00', $index + 1)),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$group->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-indication-group-members-show-more"]');
        self::assertSelectorTextContains('[data-testid="stats-indication-group-members-show-more"]', 'Show more');

        $crawler = $client->getCrawler();
        $hiddenMembers = $crawler->filter('[data-testid="stats-indication-group-members"] li.d-none');
        self::assertCount(1, $hiddenMembers);
        self::assertStringContainsString('Expand Member 4', $hiddenMembers->text());
    }

    public function testEmptyGroupWithoutIndicationsRendersEmptyState(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-empty-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $group = IndicationGroupFactory::createOne(['name' => 'Empty Group', 'createdBy' => $user]);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$group->getId(), [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-indication-group-empty"]');
        self::assertSelectorTextContains(
            '[data-testid="stats-indication-group-empty"]',
            'This group has no indications assigned yet.',
        );
        self::assertSelectorNotExists('[data-testid="stats-indication-group-heading-title"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-group-picker-card"]');
        self::assertSelectorExists('a[href*="/statistics/indication-insights"]');
    }

    public function testGroupDashboardRendersWithZeroAllocationsInPeriod(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-group-zero-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'GroupZeroState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GroupZeroDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GroupZeroHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GroupZeroSpec']);
        DepartmentFactory::createOne(['name' => 'GroupZeroDept']);
        AssignmentFactory::createOne(['name' => 'GroupZeroAssign']);
        IndicationRawFactory::createOne(['name' => 'GroupZeroRaw', 'code' => 912_367]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Zero Member A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Zero Member B']);
        $group = IndicationGroupFactory::createOne(['name' => 'Zero Allocation Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indicationA->_real());
        $groupEntity->addIndication($indicationB->_real());
        $entityManager->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/'.$group->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="stats-indication-group-empty"]');
        self::assertSelectorTextContains('[data-testid="stats-indication-group-heading-title"]', 'Zero Allocation Group');
        self::assertSelectorTextContains('[data-testid="stats-indication-case-count"]', '0');
        self::assertSelectorExists('[data-testid="stats-indication-group-picker-card"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-group-members"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-launch-button"]');
    }

    /**
     * @return array{groupId: int, hospitalId: int, indicationAId: int, indicationBId: int}
     */
    private function seedGroupDashboardFixture(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
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
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indicationA->_real());
        $groupEntity->addIndication($indicationB->_real());
        $entityManager->flush();

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

        return [
            'groupId' => (int) $group->getId(),
            'hospitalId' => (int) $hospital->getId(),
            'indicationAId' => (int) $indicationA->getId(),
            'indicationBId' => (int) $indicationB->getId(),
        ];
    }
}
