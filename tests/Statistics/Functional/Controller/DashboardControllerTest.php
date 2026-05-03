<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class DashboardControllerTest extends WebTestCase
{
    public function testStatisticsOverviewIsDisplayed(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/statistics/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-heading-scope"]');
        $this->assertSelectorExists('[data-testid="stats-heading-period"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-scope"]', 'Public');
        $this->assertSelectorTextContains('[data-testid="stats-heading-period"]', 'Last 12 months');
        $this->assertSelectorExists('[data-testid="stats-hospital-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Total allocations');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Gender distribution');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Emergency');
        $this->assertSelectorExists('[data-testid="stats-charts"]');
    }

    public function testStatisticsOverviewAcceptsScopeAndPeriodQueryParameters(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-scope"]', 'Public');
        $this->assertSelectorTextContains('[data-testid="stats-heading-period"]', 'Last 12 months');
    }

    public function testStatisticsOverviewAcceptsMonthPeriodWithYearAndMonth(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?period=month&year=2024&month=3&scope=public',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-scope"]', 'Public');
        $this->assertSelectorTextContains('[data-testid="stats-heading-period"]', '2024');
    }

    public function testStatisticsOverviewAcceptsAllTimePeriod(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-period"]', 'All time');
    }

    public function testStatisticsOverviewAcceptsYearPeriodWithYear(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-period"]', '2024');
        $this->assertSelectorTextNotContains('[data-testid="stats-heading-period"]', '%year%');
    }

    public function testOverviewHospitalSummaryLinksToAnalysisPreservesFilter(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-cross-nav-overview-kpi"]')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('scope=public', $uri);
        $this->assertStringContainsString('period=all', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
        $this->assertStringContainsString('dimension=total', $uri);
    }

    public function testOverviewGenderCardLinksToAnalysisWithGenderDimension(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-cross-nav-overview-gender"]')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('dimension=gender', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
    }

    public function testOverviewUrgencyCardLinksToAnalysisWithUrgencyDimension(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-cross-nav-overview-urgency"]')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('dimension=urgency', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
    }

    public function testOverviewKpiResourcesLinkToAnalysisWithResourcesDimension(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-cross-nav-overview-resources"]')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('dimension=resources', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
    }

    public function testOverviewIndicatorsCardLinksToAnalysisWithFeaturesDimension(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-cross-nav-overview-indicators"]')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('dimension=features', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
    }

    public function testAnalysisTableMonthRowLinksToMonthPeriod(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=table',
        );

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr:first-child td:first-child a')->link();
        $uri = $link->getUri();
        $this->assertStringContainsString('scope=public', $uri);
        $this->assertStringContainsString('period=month', $uri);
        $this->assertStringContainsString('analysis=allocations_by_month', $uri);
        $this->assertStringContainsString('view=table', $uri);
        $this->assertStringContainsString('dimension=total', $uri);
        $this->assertMatchesRegularExpression('/[?&]year=\d+/', $uri);
        $this->assertMatchesRegularExpression('/[?&]month=\d+/', $uri);
    }
}
