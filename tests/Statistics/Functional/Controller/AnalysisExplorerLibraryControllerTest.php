<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerLibraryControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use SeedsExplorerSystemViewsTrait;

    public function testLibraryRendersAllTabWithCategoryFiltersAndSystemViews(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();

        $view = self::getContainer()->get(SavedExplorerViewRepository::class)->findBySlug('allocations-over-time');
        self::assertNotNull($view?->getId());

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-tab-all"].active');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-library-tab-all"]', 'Overview');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-tab-favorites"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-library-tab-count-favorites"]', '0');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-tab-my_views"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-library-tab-count-my_views"]', '0');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-all"].btn-primary');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-filters"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-allocations"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]',
            'Time period',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-open-'.$view->getId().'"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-category-badge-allocations"]');
    }

    public function testLibraryCategoryFilterNarrowsVisibleCards(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();

        $repository = self::getContainer()->get(SavedExplorerViewRepository::class);
        $overTime = $repository->findBySlug('allocations-over-time');
        self::assertNotNull($overTime?->getId());

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all&tab=all&category=allocations',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-allocations"].btn-primary');
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-view-card-'.$overTime->getId().'"]')->count(),
        );
    }

    public function testLibrarySearchFiltersVisibleCards(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();

        $repository = self::getContainer()->get(SavedExplorerViewRepository::class);
        $genderView = $repository->findBySlug('gender-distribution');
        $overTime = $repository->findBySlug('allocations-over-time');
        self::assertNotNull($genderView?->getId());
        self::assertNotNull($overTime?->getId());

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all&search=gender',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-search-input"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-'.$genderView->getId().'"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-view-card-'.$overTime->getId().'"]');
    }
}
