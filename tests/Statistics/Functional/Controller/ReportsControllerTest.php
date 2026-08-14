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
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReportsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testReportsIndexListsAvailableReportTypes(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/reports?scope=public');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-index"]');
        $this->assertSelectorExists('[data-testid="stats-reports-card-monthly"]');
        $this->assertSelectorExists('[data-testid="stats-reports-card-transport_time_profile"]');
        $this->assertSelectorExists('a[href*="/statistics/top-lists"]');
        $this->assertStringContainsString('Reports', $crawler->filter('[data-testid="stats-heading-title"]')->text());
        $this->assertStringContainsString('Monthly report', $crawler->filter('[data-testid="stats-reports-card-title-monthly"]')->text());
        $this->assertStringContainsString('Concise summary of a completed calendar month', $crawler->filter('[data-testid="stats-reports-card-monthly"]')->text());
        $this->assertStringContainsString('Transport Time Profile', $crawler->filter('[data-testid="stats-reports-card-title-transport_time_profile"]')->text());
        $this->assertSelectorNotExists('[data-testid="stats-reports-content"]');
    }

    public function testMonthlyReportDetailRendersEmptyState(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports/monthly?scope=public',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-content"]');
        $this->assertSelectorExists('[data-testid="stats-monthly-report-empty"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"] .ps-2');
        $this->assertSelectorNotExists('[data-testid="stats-reports-print"]');
        $this->assertStringContainsString('Monthly report', $crawler->filter('[data-testid="stats-heading-title"]')->text());
    }

    public function testLegacyTypeQueryRedirectsToDetailRoute(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&type=monthly',
        );

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/reports/monthly', $location);
        $this->assertStringContainsString('scope=public', $location);
    }

    public function testUnknownReportTypeReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports/unknown_type?scope=public',
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMonthlyReportShowsPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports/monthly?scope=public&year=2024&month=3',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-nav-previous"] a.page-link[href]');
        $this->assertSelectorExists('[data-testid="stats-period-nav-next"] a.page-link[href]');
        $previousHref = $crawler->filter('[data-testid="stats-period-nav-previous"] a.page-link[href]')->attr('href');
        $nextHref = $crawler->filter('[data-testid="stats-period-nav-next"] a.page-link[href]')->attr('href');
        $this->assertStringContainsString('year=2024', (string) $previousHref);
        $this->assertStringContainsString('month=2', (string) $previousHref);
        $this->assertStringContainsString('year=2024', (string) $nextHref);
        $this->assertStringContainsString('month=4', (string) $nextHref);
    }

    public function testMonthlyReportUsesMonthOnlyPeriodControls(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports/monthly?scope=public&year=2024&month=3',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorExists('[data-testid="stats-period-secondary"]');
        $this->assertSelectorTextContains('[data-testid="stats-period-primary"]', '2024');
        $this->assertSelectorNotExists('.dropdown-menu a.dropdown-item[href*="period=all"]');
        $this->assertSelectorNotExists('.dropdown-menu a.dropdown-item[href*="period=year"]');
        $this->assertSelectorNotExists('.dropdown-menu a.dropdown-item[href*="period=quarter"]');
        $monthHref = $crawler->filter('[data-testid="stats-period-secondary"]')->closest('.btn-group')->filter('.dropdown-menu a.dropdown-item[href*="month=2"]')->attr('href');
        $this->assertNotNull($monthHref);
        $this->assertStringContainsString('year=2024', (string) $monthHref);
        $this->assertStringContainsString('/statistics/reports/monthly', (string) $monthHref);
    }

    public function testTransportTimeProfileUsesDashboardPeriodControls(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports/transport_time_profile?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-content"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-report"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-empty"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-context"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorExists('.dropdown-menu a.dropdown-item[href*="period=all"]');
        $this->assertSelectorExists('.dropdown-menu a.dropdown-item[href*="period=year"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-explorer-link"]');
        $this->assertSelectorExists('.page-header [data-testid="stats-ttp-explorer-link"].btn');
        $this->assertSelectorNotExists('[data-testid="stats-reports-print"]');
        $this->assertSelectorNotExists('[data-testid="statistics-filters-drawer-trigger"]');
        $title = $crawler->filter('[data-testid="stats-heading-title"]')->text();
        $this->assertStringContainsString('Transport Time Profile', $title);
        $this->assertStringNotContainsString('Last 12 months', $title);
        $this->assertStringNotContainsString('All time', $title);
        $context = $crawler->filter('[data-testid="stats-ttp-context"]')->text();
        $this->assertStringContainsString('all hospitals', $context);
        $this->assertStringContainsString('Last 12 months', $context);
    }

    public function testTransportTimeProfileRequiresLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/reports/transport_time_profile?scope=public');

        $this->assertResponseRedirects('/login');
    }

    public function testReportsIndexHidesPeriodControls(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/reports?scope=public');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorNotExists('.dropdown-menu a.dropdown-item[href*="period=all"]');
    }

    public function testTransportTimeProfileRendersMatrixForSeededAllocations(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne(['username' => 'ttp-ctrl-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TtpCtrlState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TtpCtrlDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TtpCtrlHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $department = DepartmentFactory::createOne(['name' => 'TtpCtrlDept']);
        SpecialityFactory::createOne(['name' => 'TtpCtrlSpec']);
        AssignmentFactory::createOne(['name' => 'TtpCtrlAssign']);
        IndicationRawFactory::createOne(['name' => 'TtpCtrlRaw', 'code' => 912_361]);
        IndicationNormalizedFactory::createOne(['name' => 'TtpCtrlNorm']);
        $import = ImportFactory::createOne(['name' => 'TtpCtrlImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:05:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2025-06-15 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 12:10:00'),
        ]);
        $unknown = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => new \DateTimeImmutable('2025-06-15 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 13:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport($import->getId());
        self::getContainer()->get(Connection::class)->update(
            'allocation_stats_projection',
            ['transport_time_minutes' => -1],
            ['id' => $unknown->getId()],
        );

        $this->loginAsRoleUser($client);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports/transport_time_profile?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-ttp-empty"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-chart-modes"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-matrix-card"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-ranked-card"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-disclaimer"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-unknown-note"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-row-volume-cases"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-row-resources-requires_cathlab"]');
        $this->assertSelectorExists('[data-testid="stats-ttp-explorer-link"]');
        $this->assertStringContainsString(
            'generic-analysis-chart',
            (string) $crawler->filter('[data-testid="stats-ttp-chart-card"]')->attr('data-controller'),
        );
    }
}
