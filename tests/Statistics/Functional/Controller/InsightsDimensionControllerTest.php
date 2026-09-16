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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightsDimensionControllerTest extends WebTestCase
{
    use Factories;

    public function testIndicationsDirectoryListsValuesAndGroupsToggle(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-dimension-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'InsightsDimensionState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightsDimensionDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightsDimensionHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightsDimensionSpec']);
        DepartmentFactory::createOne(['name' => 'InsightsDimensionDept']);
        AssignmentFactory::createOne(['name' => 'InsightsDimensionAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightsDimensionRaw', 'code' => 912_371]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Directory Indication', 'code' => 4101]);
        $group = IndicationGroupFactory::createOne(['name' => 'Directory Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(\App\Allocation\Domain\Entity\IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $import = ImportFactory::createOne(['name' => 'InsightsDimensionImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-dimension-title"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory"]');
        self::assertSelectorExists('[data-testid="stats-insights-indication-toggle"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .nav-tabs.card-header-tabs');
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .card-header [data-testid="stats-insights-directory-search"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .card-header [data-testid="stats-insights-directory-top-list"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory-sort"]');
        self::assertSelectorExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-indications"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-departments"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-tab-more"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-picker"]');
        self::assertSelectorTextContains('[data-testid="stats-insights-directory"]', 'Directory Indication');
        self::assertSelectorExists(sprintf('a[href*="/statistics/insights/indications/%d"]', $indication->getId()));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'view' => 'groups',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-insights-directory"]', 'Directory Group');
        self::assertSelectorExists(sprintf('a[href*="/statistics/insights/indication-groups/%d"]', $group->getId()));
    }

    public function testAssignmentsDirectoryListsValuesWithoutGroupsToggle(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-assignments-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'InsightsAssignState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightsAssignDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightsAssignHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightsAssignSpec']);
        DepartmentFactory::createOne(['name' => 'InsightsAssignDept']);
        $assignment = AssignmentFactory::createOne(['name' => 'Directory Assignment']);
        IndicationRawFactory::createOne(['name' => 'InsightsAssignRaw', 'code' => 912_372]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Assign Directory Indication', 'code' => 4102]);

        $import = ImportFactory::createOne(['name' => 'InsightsAssignImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'assignment' => $assignment,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/assignments', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-dimension-title"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-indication-toggle"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-directory"] .nav-tabs');
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .card-header [data-testid="stats-insights-directory-search"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .card-header [data-testid="stats-insights-directory-top-list"]');
        self::assertSelectorExists('[data-testid="stats-insights-directory-sort"]');
        self::assertSelectorTextContains('[data-testid="stats-insights-directory"]', 'Directory Assignment');
        self::assertSelectorExists(sprintf('a[href*="/statistics/insights/assignments/%d"]', $assignment->getId()));
        self::assertSelectorExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorExists('[data-testid="stats-insights-tab-assignments"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-tab-more"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-picker"]');
    }

    public function testDirectoryCanSortAlphabetically(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-sort-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        AssignmentFactory::createOne(['name' => 'Zebra Assignment']);
        AssignmentFactory::createOne(['name' => 'Alpha Assignment']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/assignments', [
            'scope' => 'public',
            'period' => 'all',
            'sort' => 'alpha',
        ]);

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->filter('[data-testid="stats-insights-directory"]')->text();
        $alphaPos = strpos($text, 'Alpha Assignment');
        $zebraPos = strpos($text, 'Zebra Assignment');
        self::assertNotFalse($alphaPos);
        self::assertNotFalse($zebraPos);
        self::assertLessThan($zebraPos, $alphaPos);
        $selected = $client->getCrawler()->filter('#stats-insights-directory-sort option[selected]')->attr('value');
        self::assertSame('alpha', $selected);
    }

    public function testIndicationsDirectoryPaginationKeepsDimensionRouteParam(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-dimension-page-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        IndicationNormalizedFactory::createMany(26, static fn (int $i): array => [
            'name' => sprintf('Paged Indication %02d', $i),
            'code' => 4200 + $i,
        ]);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-insights-directory"] .card-footer');
        self::assertSelectorExists('[data-testid="stats-insights-directory-sort"]');
        $limitHref = $client->getCrawler()->filter('[data-testid="stats-insights-directory"] .dropdown-menu a')->first()->attr('href');
        self::assertNotNull($limitHref);
        self::assertStringContainsString('/statistics/insights/indications', $limitHref);
        self::assertStringContainsString('limit=25', $limitHref);
    }

    public function testUnknownDimensionReturns404(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-dimension-404-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/hospitals', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
