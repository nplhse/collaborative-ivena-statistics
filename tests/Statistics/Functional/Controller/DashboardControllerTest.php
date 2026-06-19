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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
class DashboardControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testStatisticsOverviewIsDisplayed(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-subtitle"]', 'Dashboard view');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
        $this->assertSelectorExists('[data-testid="stats-executive-dashboard"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Total allocations');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Gender distribution');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Emergency');
        $this->assertSelectorExists('[data-testid="stats-executive-kpis"]');
        $this->assertCount(6, $crawler->filter('[data-testid="stats-executive-kpis"] .card'));
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-cases_per_day"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-median_age"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-age_80_plus"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-night_daytime"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-weekend"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-median_transport"]');
        $this->assertSelectorExists('[data-testid="stats-executive-indications"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-reports-frame"]');
        $this->assertSelectorNotExists('[data-testid="stats-overview-top-specialities"]');
        $this->assertSelectorExists('[data-testid="stats-overview-time-series"]');
        $this->assertSelectorExists('[data-testid="stats-overview-heatmap"]');
        $this->assertSelectorExists('[data-testid="stats-overview-age-groups"]');
        $this->assertSelectorExists('[data-testid="stats-overview-transport"]');
        $this->assertSelectorExists('[data-testid="stats-overview-transport-time"]');
        $this->assertSelectorExists('[data-testid="stats-overview-features"]');
        $this->assertSelectorExists('[data-testid="stats-overview-resources"]');
        $this->assertSelectorExists('[data-testid="stats-charts"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-indicator"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-drawer"]');
        $this->assertSelectorNotExists('[data-testid="stats-data-quality-dimensions-table"]');
    }

    public function testOverviewRendersHospitalInsightsWhenBenchmarkDataIsSufficient(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedOverviewHospitalInsightsScenario();

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=month&year=2026&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-overview-hospital-insights"]');
        $this->assertGreaterThanOrEqual(
            1,
            $crawler->filter('[data-testid^="stats-overview-hospital-insight-"]')->count(),
        );
    }

    public function testOverviewRedirectsToLast12MonthsWhenEnoughMonthlyDataExists(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedDefaultPeriodScenario(7);

        $client->request(Request::METHOD_GET, '/statistics/?scope=public');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('period=all', $location);
        self::assertStringContainsString('scope=public', $location);
    }

    public function testOverviewKeepsAllTimeWhenFewerThanSevenMonthsHaveData(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedDefaultPeriodScenario(3);

        $client->request(Request::METHOD_GET, '/statistics/?scope=public');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('period=all', $client->getRequest()->getUri());
    }

    public function testOverviewTopReportsLazyEndpointRendersAllCards(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/overview/top-reports?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-overview-top-reports');
        $this->assertSelectorExists('[data-testid="stats-overview-top-specialities"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-departments"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-assignments"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-occasions"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-infections"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-secondary-indications"]');
    }

    public function testDataQualityDrawerEndpointRendersDimensions(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/data-quality/drawer?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-data-quality-dimensions-table"]');
    }

    public function testStatisticsOverviewAcceptsScopeAndPeriodQueryParameters(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testStatisticsOverviewAcceptsMonthPeriodWithYearAndMonth(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?period=month&year=2024&month=3&scope=public',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testStatisticsOverviewAcceptsAllTimePeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testHospitalCohortWithTooFewHospitalsRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=hospital_cohort&cohort=urban_basic',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('cohort=', $location);
    }

    public function testHospitalCohortWithEnoughHospitalsShowsTranslatedLabel(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedEligibleUrbanBasicCohort($client);

        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=hospital_cohort:urban_basic&period=all',
        );

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('stats.filter.cohort.', $content);
        self::assertStringContainsString('Urban Location Basic Tier', $content);
    }

    public function testStateScopeWithoutStateIdRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/?scope=state&period=all');

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
    }

    public function testUnknownStateIdRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/?scope=state&state=999999&period=all');

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
    }

    public function testScopeSidebarShowsHospitalCohortGroup(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $crawler->filter('#statistics-filters-drawer .offcanvas-body')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text('', true), 'Urban')
                || str_contains($node->text('', true), 'Rural')
        );

        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
    }

    public function testStatisticsOverviewAcceptsYearPeriodWithYear(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testOverviewFeatureAndResourceCardsHaveRows(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=month&year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $this->assertCount(7, $crawler->filter('[data-testid="stats-overview-features"] .progress'));
        $this->assertCount(2, $crawler->filter('[data-testid="stats-overview-resources"] .progress'));
    }

    public function testOverviewShowsPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=year&year=2021');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorTextContains('[data-testid="stats-period-secondary"]', '2021');
        $this->assertSelectorTextContains('[data-testid="stats-period-nav-previous"] .page-item-title', '2020');
        $this->assertSelectorTextContains('[data-testid="stats-period-nav-next"] .page-item-title', '2022');
    }

    public function testOverviewQuarterPeriodIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=quarter&year=2021&quarter=2',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-secondary"]');
    }

    public function testOverviewYearModeSecondaryListsYearsOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-secondary"]');
        $this->assertCount(
            0,
            $crawler->filter('[data-testid="stats-period-secondary"] + .dropdown-menu a[href*="period=month"]'),
        );
    }

    public function testOverviewAllTimeHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
    }

    public function testOverviewLast12MonthsHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
    }

    public function testOverviewMonthPeriodShowsPreviousAndNextOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=month&year=2021&month=1',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-nav-previous"]');
        $this->assertSelectorExists('[data-testid="stats-period-nav-next"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-nav-parent"]');
    }

    public function testOverviewLast12MonthsAppearsInPeriodPrimaryMenu(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-period-primary"] + .dropdown-menu a[href*="period=all"]')->count(),
        );
    }

    private function seedOverviewHospitalInsightsScenario(): void
    {
        $user = UserFactory::createOne(['username' => 'overview-insights-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewInsightsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewInsightsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewInsightsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewInsightsSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewInsightsDept']);
        AssignmentFactory::createOne(['name' => 'OverviewInsightsAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewInsightsRaw', 'code' => 912_361]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'OverviewInsightsNorm']);

        $import = ImportFactory::createOne([
            'name' => 'OverviewInsightsImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        $allocationDefaults = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationNormalized,
            'isWithPhysician' => true,
        ];

        AllocationFactory::createMany(340, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-02-15 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-02-15 09:20:00'),
        ]);
        AllocationFactory::createMany(50, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-05-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-10 10:20:00'),
        ]);
        AllocationFactory::createMany(110, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-06-10 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-06-10 11:20:00'),
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
        $this->refreshOverviewMaterializedViews();
    }

    private function seedDefaultPeriodScenario(int $monthCount): void
    {
        $user = UserFactory::createOne(['username' => 'default-period-fn-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DefaultPeriodFnState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DefaultPeriodFnDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DefaultPeriodFnHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'DefaultPeriodFnSpec']);
        DepartmentFactory::createOne(['name' => 'DefaultPeriodFnDept']);
        AssignmentFactory::createOne(['name' => 'DefaultPeriodFnAssign']);
        IndicationRawFactory::createOne(['name' => 'DefaultPeriodFnRaw', 'code' => 912_371]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DefaultPeriodFnNorm']);

        $import = ImportFactory::createOne([
            'name' => 'DefaultPeriodFnImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        $start = new \DateTimeImmutable('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
        for ($offset = 0; $offset < $monthCount; ++$offset) {
            $createdAt = $start->modify(sprintf('+%d months', $offset))->modify('+10 days');
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'indicationNormalized' => $indicationNormalized,
                'createdAt' => $createdAt,
                'arrivalAt' => $createdAt->modify('+20 minutes'),
            ]);
        }

        $this->rebuildProjectionForImports([(int) $import->getId()]);
        $this->refreshOverviewMaterializedViews();
    }
}
