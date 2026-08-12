<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
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
        $this->assertSelectorExists('a[href*="/statistics/top-lists"]');
        $this->assertStringContainsString('Reports', $crawler->filter('[data-testid="stats-heading-title"]')->text());
        $this->assertStringContainsString('Monthly report', $crawler->filter('[data-testid="stats-reports-card-title-monthly"]')->text());
        $this->assertSelectorNotExists('[data-testid="stats-reports-content"]');
    }

    public function testMonthlyReportDetailRendersEmptyState(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports/monthly?scope=public',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-content"]');
        $this->assertSelectorExists('[data-testid="stats-monthly-report-empty"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
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

    public function testReportsIndexHidesPeriodControls(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/reports?scope=public');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorNotExists('.dropdown-menu a.dropdown-item[href*="period=all"]');
    }
}
