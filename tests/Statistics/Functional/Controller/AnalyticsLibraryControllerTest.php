<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalyticsLibraryControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

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
        $this->assertSelectorNotExists('[data-testid="stats-analytics-builder-entry"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-builder-header-link"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-library-search"]');
    }

    public function testViewPageRendersChartWithoutCustomizeDrawerForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-title"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-customize-drawer"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-customize-open"]');
    }

    public function testViewPageRendersCustomizeDrawerForParticipant(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
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
        $client = $this->createClientAsParticipant();
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

    public function testAnalyticsExplorerRedirectsToLibrary(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics?scope=public&period=all',
        );

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/analytics/library', $location);
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringContainsString('period=all', $location);
    }

    public function testToggleFavoriteFromLibraryCardShowsInFavoritesTab(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->submitFavoriteToggle($client, $crawler, 'allocations_by_month');

        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all&tab=favorites',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-card-allocations_by_month"]');
        $this->assertSelectorExists('[data-testid="stats-analytics-favorite-allocations_by_month"].text-yellow');
    }

    public function testRecentTabShowsLastUsedView(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all&tab=recent',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-card-allocations_by_month"]');
    }

    public function testUnknownAnalyticsViewReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/unknown_preset?scope=public&period=all',
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCategoryFilterLimitsViews(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all&tab=categories&category=clinical',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-card-urgency_distribution_with_share"]');
        $this->assertSelectorNotExists('[data-testid="stats-analytics-view-card-allocations_by_month"]');
    }

    public function testRoleUserSeesPublicStateDispatchScopesOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('Public', $labels);
        self::assertNotContains('My hospitals', $labels);
        self::assertNotContains('Hospitals', $labels);
    }

    public function testParticipantWithOwnedHospitalsSeesMyHospitalsLabel(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        HospitalFactory::createOne(['owner' => $user]);
        $client->loginUser($user->_real());

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=my_hospitals&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('My hospitals', $labels);
        self::assertNotContains('Hospitals', $labels);
    }

    public function testAdminSeesHospitalsLabel(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(2);
        $client->loginUser($user->_real());

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=my_hospitals&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('Hospitals', $labels);
        self::assertNotContains('My hospitals', $labels);
    }

    public function testInvalidMyHospitalsScopeRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=my_hospitals&period=all',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('my_hospitals', $location);
    }

    private function submitFavoriteToggle(KernelBrowser $client, Crawler $crawler, string $viewKey): void
    {
        $button = $crawler->filter('[data-testid="stats-analytics-favorite-'.$viewKey.'"]');
        self::assertGreaterThan(0, $button->count());

        $form = $button->form();
        $client->submit($form);
        $this->assertResponseRedirects();
        $client->followRedirect();
    }

    /**
     * @return list<string>
     */
    private function scopePrimaryMenuLabels(Crawler $crawler): array
    {
        return $crawler
            ->filter('.page-header .dropdown-menu .dropdown-item')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
