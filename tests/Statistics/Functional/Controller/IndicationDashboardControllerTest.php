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
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationDashboardControllerTest extends WebTestCase
{
    use Factories;

    public function testDashboardRendersForIndication(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-dash-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'IndicationDashState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationDashDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationDashHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationDashSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationDashDept']);
        AssignmentFactory::createOne(['name' => 'IndicationDashAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationDashRaw', 'code' => 912_350]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Dashboard Test Indication', 'code' => 1001]);

        $import = ImportFactory::createOne(['name' => 'IndicationDashImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 55,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/'.$indication->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="stats-indication-picker"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorTextSame('[data-testid="stats-indication-heading-title"]', 'Dashboard Test Indication (1001)');
        self::assertSelectorExists('[data-testid="stats-indication-kpi"]');
        self::assertSelectorExists('[data-testid="stats-indication-gender"]');
        self::assertSelectorExists('[data-testid="stats-indication-urgency"]');
        self::assertSelectorExists('[data-testid="stats-indication-time-series"]');
        self::assertSelectorExists('[data-testid="stats-indication-heatmap"]');
        self::assertSelectorExists('[data-testid="stats-indication-age-groups"]');
        self::assertSelectorExists('[data-testid="stats-indication-resources"]');
        self::assertSelectorExists('[data-testid="stats-indication-clinical"]');
        self::assertSelectorExists('[data-testid="stats-indication-transport-time"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-age-histogram"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-weekday"]');
        self::assertSelectorExists('[data-testid="stats-data-quality-indicator"]');
        self::assertSelectorExists('[data-testid="stats-data-quality-drawer"]');
        self::assertSelectorExists('[data-testid="stats-indication-header-actions"].btn-group');
        self::assertSelectorExists('[data-testid="stats-indication-header-actions"] [data-testid="stats-indication-catalog-link"].btn');
        self::assertSelectorExists('[data-testid="stats-indication-header-actions"] [data-testid="stats-indication-compare-launch-button"].btn');
        self::assertSelectorNotExists('[data-testid="stats-indication-compare-launch-button"].btn-outline-primary');
        self::assertSelectorExists('[data-testid="stats-indication-compare-launch-modal"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-compare-cta"]');
        $crawler = $client->getCrawler();
        self::assertStringContainsString(
            'Dashboard Test Indication',
            (string) $crawler->filter('#stats-indication-compare-launch-a')->attr('value'),
        );
        $catalogHref = $crawler->filter('[data-testid="stats-indication-catalog-link"]')->attr('href');
        self::assertNotNull($catalogHref);
        self::assertStringContainsString('/explore/indication/'.$indication->getPublicIdString(), $catalogHref);
        $topListHref = $crawler->filter('[data-testid="stats-indication-top-list-link"]')->attr('href');
        self::assertNotNull($topListHref);
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $topListHref);
        self::assertStringContainsString('scope=hospital', $topListHref);
    }

    public function testUnknownIndicationReturns404(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-dash-404-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/999999', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDashboardRendersForAssignmentDimension(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'assignment-dash-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'AssignmentDashState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'AssignmentDashDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'AssignmentDashHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'AssignmentDashSpec']);
        DepartmentFactory::createOne(['name' => 'AssignmentDashDept']);
        $assignment = AssignmentFactory::createOne(['name' => 'Dashboard Test Assignment']);
        IndicationRawFactory::createOne(['name' => 'AssignmentDashRaw', 'code' => 912_380]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Assignment Dash Indication', 'code' => 5101]);

        $import = ImportFactory::createOne(['name' => 'AssignmentDashImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 55,
            'assignment' => $assignment,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/assignments/'.$assignment->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-indication-heading-title"]', 'Dashboard Test Assignment');
        self::assertSelectorExists('[data-testid="stats-indication-kpi"]');
        self::assertSelectorNotExists('[data-testid="stats-insights-subnav"]');
        self::assertSelectorNotExists('[data-testid="stats-indication-picker"]');
    }

    public function testDashboardRendersForInfectionDimension(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'infection-dash-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'InfectionDashState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InfectionDashDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InfectionDashHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InfectionDashSpec']);
        DepartmentFactory::createOne(['name' => 'InfectionDashDept']);
        AssignmentFactory::createOne(['name' => 'InfectionDashAssign']);
        $infection = InfectionFactory::createOne(['name' => 'Dashboard Test Infection']);
        IndicationRawFactory::createOne(['name' => 'InfectionDashRaw', 'code' => 912_390]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Infection Dash Indication', 'code' => 6101]);

        $import = ImportFactory::createOne(['name' => 'InfectionDashImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 55,
            'infection' => $infection,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/infections/'.$infection->getId(), [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="stats-indication-heading-title"]', 'Dashboard Test Infection');
        self::assertSelectorExists('[data-testid="stats-indication-kpi"]');
        self::assertSelectorExists('[data-testid="stats-indication-top-list-link"]');
    }

    public function testUndersizedHospitalCohortRedirectsDashboardToPublic(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-dash-cohort-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Cohort Redirect Indication']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/insights/indications/'.$indication->getId(), [
            'scope' => 'hospital_cohort',
            'cohort' => 'urban_basic',
            'period' => 'all',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('/statistics/insights/indications/'.$indication->getId(), $location);
    }
}
