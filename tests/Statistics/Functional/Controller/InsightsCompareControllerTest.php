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
final class InsightsCompareControllerTest extends WebTestCase
{
    use Factories;

    public function testCompareRendersKpiTable(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CompareSpec']);
        DepartmentFactory::createOne(['name' => 'CompareDept']);
        AssignmentFactory::createOne(['name' => 'CompareAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareRaw', 'code' => 912_361]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Compare Indication A', 'code' => 2001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Compare Indication B', 'code' => 2002]);

        $import = ImportFactory::createOne(['name' => 'CompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
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

        AllocationFactory::createMany(2, [
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

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'indication_a' => (string) $indicationA->getId(),
            'indication_b' => (string) $indicationB->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-kpi-tiles"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-edit-button"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-edit-modal"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-disable"].btn-outline-danger');
        self::assertSelectorExists('[data-testid="stats-insights-compare-swap"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-search"]');
        $crawler = $client->getCrawler();
        $disableHref = $crawler->filter('[data-testid="stats-insights-compare-disable"]')->attr('href');
        self::assertNotNull($disableHref);
        self::assertStringContainsString('/statistics/insights/indications/'.$indicationA->getId(), $disableHref);
        self::assertStringNotContainsString('subject_b', $disableHref);
        self::assertStringNotContainsString('/compare', $disableHref);
        self::assertStringContainsString(
            '/statistics/insights/indications/'.$indicationA->getId(),
            (string) $crawler->filter('[data-testid="stats-insights-compare-label-a"]')->attr('href'),
        );
        self::assertStringContainsString(
            '/statistics/insights/indications/'.$indicationB->getId(),
            (string) $crawler->filter('[data-testid="stats-insights-compare-label-b"]')->attr('href'),
        );
        self::assertSelectorExists('[data-testid="stats-insights-compare-case-distribution"]');
        self::assertSelectorExists('[data-benchmarking-charts-target="caseDistributionHeatmap"]');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Compare Indication A');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Compare Indication B');
        self::assertSelectorExists('[data-testid="stats-insights-compare-gender"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-urgency"]');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-urgency"]', 'Emergency');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-urgency"]', 'Inpatient');
        self::assertSelectorNotExists('[data-testid="stats-insights-compare-insights"]');
    }

    public function testCompareRendersInsightsWhenSampleLargeEnough(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-insights-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareInsightsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareInsightsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareInsightsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CompareInsightsSpec']);
        DepartmentFactory::createOne(['name' => 'CompareInsightsDept']);
        AssignmentFactory::createOne(['name' => 'CompareInsightsAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareInsightsRaw', 'code' => 912_364]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Insights Indication A', 'code' => 3001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Insights Indication B', 'code' => 3002]);

        $import = ImportFactory::createOne(['name' => 'CompareInsightsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'isWithPhysician' => false,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        AllocationFactory::createMany(10, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-03 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-03 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'indication_a' => (string) $indicationA->getId(),
            'indication_b' => (string) $indicationB->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-compare-insights"]');
        self::assertSelectorExists('[data-testid="stats-insights-compare-insight-physician"]');
    }

    public function testRedirectsWhenSameIndicationSelected(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-same-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Same Indication']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indication->getId(),
            'subject_b_dimension' => 'indications',
            'subject_b_id' => (string) $indication->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/statistics/insights/indications/'.$indication->getId(),
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testCompareSingleVsGroupWithSubjectParameters(): void
    {
        $client = self::createClient();
        $fixture = $this->seedGroupCompareFixture($client);

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
            'subject_a_type' => 'single',
            'subject_a_id' => (string) $fixture['indicationCId'],
            'subject_b_type' => 'group',
            'subject_b_id' => (string) $fixture['groupId'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Compare Single C');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Compare Group');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '5');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '5');

        $crawler = $client->getCrawler();
        self::assertStringContainsString(
            '/statistics/insights/indications/'.$fixture['indicationCId'],
            (string) $crawler->filter('[data-testid="stats-insights-compare-label-a"]')->attr('href'),
        );
        self::assertStringContainsString(
            '/statistics/insights/indication-groups/'.$fixture['groupId'],
            (string) $crawler->filter('[data-testid="stats-insights-compare-label-b"]')->attr('href'),
        );
    }

    public function testCompareGroupVsGroup(): void
    {
        $client = self::createClient();
        $fixture = $this->seedGroupCompareFixture($client);

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
            'subject_a_type' => 'group',
            'subject_a_id' => (string) $fixture['groupId'],
            'subject_b_type' => 'group',
            'subject_b_id' => (string) $fixture['otherGroupId'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Compare Group');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Other Group');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '5');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '2');
    }

    public function testCompareShowsOverlapNoticeWhenSubjectsShareIndications(): void
    {
        $client = self::createClient();
        $fixture = $this->seedGroupCompareFixture($client);

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
            'subject_a_type' => 'single',
            'subject_a_id' => (string) $fixture['indicationAId'],
            'subject_b_type' => 'group',
            'subject_b_id' => (string) $fixture['groupId'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-compare-overlap-notice"]');
    }

    public function testRedirectsWhenEmptyGroupSelected(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-empty-group-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Non Group Indication']);
        $emptyGroup = IndicationGroupFactory::createOne(['name' => 'Empty Compare Group', 'createdBy' => $user]);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indication->getId(),
            'subject_b_dimension' => 'indication-groups',
            'subject_b_id' => (string) $emptyGroup->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/statistics/insights', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testRedirectsWhenSameGroupSelected(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-same-group-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $group = IndicationGroupFactory::createOne(['name' => 'Same Group', 'createdBy' => $user]);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'indication-groups',
            'subject_a_id' => (string) $group->getId(),
            'subject_b_dimension' => 'indication-groups',
            'subject_b_id' => (string) $group->getId(),
        ]);

        self::assertResponseRedirects();
    }

    /**
     * @return array{
     *     groupId: int,
     *     otherGroupId: int,
     *     hospitalId: int,
     *     indicationAId: int,
     *     indicationBId: int,
     *     indicationCId: int
     * }
     */
    private function seedGroupCompareFixture(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $user = UserFactory::createOne(['username' => 'indication-compare-group-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareGroupState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareGroupDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareGroupHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CompareGroupSpec']);
        DepartmentFactory::createOne(['name' => 'CompareGroupDept']);
        AssignmentFactory::createOne(['name' => 'CompareGroupAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareGroupRaw', 'code' => 912_365]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Group Member A', 'code' => 4001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Group Member B', 'code' => 4002]);
        $indicationC = IndicationNormalizedFactory::createOne(['name' => 'Compare Single C', 'code' => 4003]);
        $indicationD = IndicationNormalizedFactory::createOne(['name' => 'Other Group Member', 'code' => 4004]);

        $group = IndicationGroupFactory::createOne(['name' => 'Compare Group', 'createdBy' => $user]);
        $otherGroup = IndicationGroupFactory::createOne(['name' => 'Other Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        $otherGroupEntity = $entityManager->find(IndicationGroup::class, $otherGroup->getId());
        self::assertNotNull($groupEntity);
        self::assertNotNull($otherGroupEntity);
        $groupEntity->addIndication($indicationA);
        $groupEntity->addIndication($indicationB);
        $otherGroupEntity->addIndication($indicationD);
        $entityManager->flush();

        $import = ImportFactory::createOne(['name' => 'CompareGroupImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationC,
            'createdAt' => new \DateTimeImmutable('2026-05-03 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-03 12:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationD,
            'createdAt' => new \DateTimeImmutable('2026-05-04 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-04 13:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        return [
            'groupId' => (int) $group->getId(),
            'otherGroupId' => (int) $otherGroup->getId(),
            'hospitalId' => (int) $hospital->getId(),
            'indicationAId' => (int) $indicationA->getId(),
            'indicationBId' => (int) $indicationB->getId(),
            'indicationCId' => (int) $indicationC->getId(),
        ];
    }

    public function testCompareWithoutSubjectsRedirectsToDirectory(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-empty-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights', $location);
        self::assertStringNotContainsString('/compare', $location);
    }

    public function testCompareRendersForAssignments(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'assignment-compare-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'AssignCompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'AssignCompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'AssignCompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'AssignCompareSpec']);
        DepartmentFactory::createOne(['name' => 'AssignCompareDept']);
        $assignmentA = AssignmentFactory::createOne(['name' => 'Compare Assignment A']);
        $assignmentB = AssignmentFactory::createOne(['name' => 'Compare Assignment B']);
        IndicationRawFactory::createOne(['name' => 'AssignCompareRaw', 'code' => 912_381]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Assign Compare Indication', 'code' => 5101]);
        $import = ImportFactory::createOne(['name' => 'AssignCompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'assignment' => $assignmentA,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'assignment' => $assignmentB,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-02 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->followRedirects();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/assignments/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'subject_a_id' => (string) $assignmentA->getId(),
            'subject_b_id' => (string) $assignmentB->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Compare Assignment A');
        self::assertSelectorTextContains('body', 'Compare Assignment B');
    }

    public function testCompareSameAssignmentRedirectsToDirectory(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'assignment-compare-same-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $assignment = AssignmentFactory::createOne(['name' => 'Same Assignment']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'assignments',
            'subject_a_id' => (string) $assignment->getId(),
            'subject_b_dimension' => 'assignments',
            'subject_b_id' => (string) $assignment->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/statistics/insights/assignments/'.$assignment->getId(),
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testCompareUnknownAssignmentIdsRedirectToDirectory(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'assignment-compare-unknown-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'assignments',
            'subject_a_id' => '999999',
            'subject_b_dimension' => 'assignments',
            'subject_b_id' => '888888',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights', $location);
        self::assertStringNotContainsString('/compare', $location);
    }

    public function testCompareRejectsNonNumericSubjectIds(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'assignment-compare-nan-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'public',
            'period' => 'all',
            'subject_a_dimension' => 'assignments',
            'subject_a_id' => 'abc',
            'subject_b_dimension' => 'assignments',
            'subject_b_id' => '12',
        ]);

        self::assertResponseRedirects();
    }

    public function testUndersizedHospitalCohortRedirectsCompareToPublic(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'compare-cohort-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'hospital_cohort',
            'cohort' => 'urban_basic',
            'period' => 'all',
            'subject_a_dimension' => 'assignments',
            'subject_a_id' => '1',
            'subject_b_dimension' => 'assignments',
            'subject_b_id' => '2',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('scope=public', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testLegacyDimensionUrlRedirectsToCanonicalCompare(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-legacy-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Legacy Compare A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Legacy Compare B']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/compare', [
            'scope' => 'public',
            'period' => 'all',
            'indication_a' => (string) $indicationA->getId(),
            'indication_b' => (string) $indicationB->getId(),
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights/compare', $location);
        self::assertStringContainsString('subject_a_dimension=indications', $location);
        self::assertStringContainsString('subject_a_id='.$indicationA->getId(), $location);
        self::assertStringContainsString('subject_b_id='.$indicationB->getId(), $location);
        self::assertStringNotContainsString('indication_a=', $location);
    }

    public function testAllowsSameSubjectWithDifferentPeriods(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-period-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'ComparePeriodState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'ComparePeriodDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'ComparePeriodHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'ComparePeriodSpec']);
        DepartmentFactory::createOne(['name' => 'ComparePeriodDept']);
        AssignmentFactory::createOne(['name' => 'ComparePeriodAssign']);
        IndicationRawFactory::createOne(['name' => 'ComparePeriodRaw', 'code' => 912_370]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Period Compare Indication', 'code' => 4010]);
        $import = ImportFactory::createOne(['name' => 'ComparePeriodImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 10:20:00'),
        ]);
        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2025-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-03-01 10:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'year',
            'year' => '2026',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indication->getId(),
            'subject_b_dimension' => 'indications',
            'subject_b_id' => (string) $indication->getId(),
            'comparison_period' => 'year',
            'comparison_year' => '2025',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Period Compare Indication');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Period Compare Indication');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '3');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '2');
        self::assertSelectorExists('[data-testid="stats-insights-compare-search"]');
    }

    public function testComparisonScopeChangesOnlySideB(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-dual-scope-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareDualScopeState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareDualScopeDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'CompareDualScopeHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'CompareDualScopeHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'CompareDualScopeSpec']);
        DepartmentFactory::createOne(['name' => 'CompareDualScopeDept']);
        AssignmentFactory::createOne(['name' => 'CompareDualScopeAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareDualScopeRaw', 'code' => 912_371]);
        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Dual Scope Indication A', 'code' => 4011]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Dual Scope Indication B', 'code' => 4012]);
        $importA = ImportFactory::createOne(['name' => 'CompareDualScopeImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'CompareDualScopeImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-04-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 10:20:00'),
        ]);
        AllocationFactory::createMany(1, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-04-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 10:20:00'),
        ]);
        AllocationFactory::createMany(6, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-04-03 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-03 10:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($importA->getId());
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($importB->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospitalA->getId(),
            'period' => 'all',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indicationA->getId(),
            'subject_b_dimension' => 'indications',
            'subject_b_id' => (string) $indicationB->getId(),
            'comparison_scope' => 'hospital',
            'comparison_hospital' => (string) $hospitalB->getId(),
            'comparison_period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '4');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '6');
    }

    public function testCrossDimensionCompareRendersBothSubjects(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-cross-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareCrossState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareCrossDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareCrossHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'CompareCrossSpec']);
        DepartmentFactory::createOne(['name' => 'CompareCrossDept']);
        $assignment = AssignmentFactory::createOne(['name' => 'Cross Compare Assignment']);
        IndicationRawFactory::createOne(['name' => 'CompareCrossRaw', 'code' => 912_372]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Cross Compare Indication', 'code' => 4013]);
        $import = ImportFactory::createOne(['name' => 'CompareCrossImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'assignment' => $assignment,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indication->getId(),
            'subject_b_dimension' => 'assignments',
            'subject_b_id' => (string) $assignment->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Cross Compare Indication');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Cross Compare Assignment');
        $crawler = $client->getCrawler();
        self::assertStringContainsString(
            'Indications',
            (string) $crawler->filter('[data-testid="stats-insights-compare-period-a"]')->text(),
        );
        self::assertStringContainsString(
            'Assignment',
            (string) $crawler->filter('[data-testid="stats-insights-compare-period-b"]')->text(),
        );
    }

    public function testSwapExchangesSubjectsAndFilters(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-swap-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareSwapState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareSwapDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareSwapHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'CompareSwapSpec']);
        DepartmentFactory::createOne(['name' => 'CompareSwapDept']);
        AssignmentFactory::createOne(['name' => 'CompareSwapAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareSwapRaw', 'code' => 912_373]);
        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Swap Indication A', 'code' => 4014]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Swap Indication B', 'code' => 4015]);
        $import = ImportFactory::createOne(['name' => 'CompareSwapImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);
        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2025-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-05-01 10:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'year',
            'year' => '2026',
            'subject_a_dimension' => 'indications',
            'subject_a_id' => (string) $indicationA->getId(),
            'subject_b_dimension' => 'indications',
            'subject_b_id' => (string) $indicationB->getId(),
            'comparison_scope' => 'hospital',
            'comparison_hospital' => (string) $hospital->getId(),
            'comparison_period' => 'year',
            'comparison_year' => '2025',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Swap Indication A');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Swap Indication B');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '4');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '2');

        $swapUrl = $client->getCrawler()->filter('[data-testid="stats-insights-compare-swap"]')->attr('href');
        self::assertNotNull($swapUrl);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $swapUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-a"]', 'Swap Indication B');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-label-b"]', 'Swap Indication A');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-a"]', '2');
        self::assertSelectorTextContains('[data-testid="stats-insights-compare-count-b"]', '4');
        $location = (string) $client->getRequest()->getUri();
        self::assertStringContainsString('subject_a_id='.$indicationB->getId(), $location);
        self::assertStringContainsString('comparison_year=2026', $location);
        self::assertStringContainsString('year=2025', $location);
    }
}
