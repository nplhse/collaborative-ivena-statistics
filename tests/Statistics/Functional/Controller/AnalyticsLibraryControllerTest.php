<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AnalyticsLibraryControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testLibraryRendersWithRecommendedViews(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-library"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-view-card-allocations_by_month"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-builder-entry"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-builder-header-link"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-library-search"]');
    }

    public function testViewPageRendersChartAndCustomizeDrawer(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-title"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-customize-drawer"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-save-view-title"]');
    }

    public function testCategoriesTabSearchFiltersViews(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all&tab=categories&q=resus',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-library-search"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-view-card-resus_by_hour"]');
    }

    public function testBuilderRendersSingleConfigurationForm(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/builder?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-builder-form"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-builder-launch"]');
        $this->assertSelectorNotExists('.steps');
    }

    public function testCategoryBadgeLinksToCategoriesTab(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-testid="stats-analytics-category-badge-time_and_trends"]')->first();
        $this->assertGreaterThan(0, $badge->count());
        $this->assertStringContainsString('tab=categories', (string) $badge->attr('href'));
        $this->assertStringContainsString('category=time_and_trends', (string) $badge->attr('href'));
    }
}
