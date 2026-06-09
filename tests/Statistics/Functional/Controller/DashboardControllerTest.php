<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DashboardControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testStatisticsOverviewIsDisplayed(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-subtitle"]', 'Dashboard view');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
        $this->assertSelectorExists('[data-testid="stats-hospital-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Total allocations');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Gender distribution');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Emergency');
        $this->assertSelectorExists('[data-testid="stats-overview-features"]');
        $this->assertSelectorExists('[data-testid="stats-overview-resources"]');
        $this->assertSelectorTextContains('[data-testid="stats-overview-features"]', 'Clinical features');
        $this->assertSelectorTextContains('[data-testid="stats-overview-resources"]', 'Resources');
        $this->assertSelectorExists('[data-testid="stats-charts"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-indicator"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-drawer"]');
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

    private function seedEligibleUrbanBasicCohort(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $user = UserFactory::createOne(['username' => 'dashboard-cohort-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Dashboard Cohort State']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Dashboard Cohort Dispatch']);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'Dashboard Cohort Hospital A',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'Dashboard Cohort Hospital B',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'Dashboard Cohort Spec']);
        DepartmentFactory::createOne(['name' => 'Dashboard Cohort Dept']);
        AssignmentFactory::createOne(['name' => 'Dashboard Cohort Assign']);
        OccasionFactory::createOne(['name' => 'Dashboard Cohort Occ']);
        InfectionFactory::createOne(['name' => 'Dashboard Cohort Inf']);
        $raw = IndicationRawFactory::createOne(['name' => 'Dashboard Cohort Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Dashboard Cohort Norm']);
        $importA = ImportFactory::createOne(['name' => 'Dashboard Cohort Import A', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'Dashboard Cohort Import B', 'hospital' => $hospitalB, 'createdBy' => $user]);

        foreach ([[$importA, $hospitalA], [$importB, $hospitalB]] as [$import, $hospital]) {
            AllocationFactory::createOne([
                'createdAt' => new \DateTimeImmutable('today'),
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
            ]);
        }

        $container = $client->getContainer();
        $rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $container->get(MaterializedViewRefresher::class)->refresh(
            [StatisticsMaterializedViewGroups::OVERVIEW],
            concurrently: false,
        );
    }
}
