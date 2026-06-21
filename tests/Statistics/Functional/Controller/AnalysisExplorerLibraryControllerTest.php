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

    public function testLibraryRendersSectionsAndSystemViews(): void
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
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-section-favorites"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-section-my_views"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-section-system"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-allocations"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]',
            'Total allocations',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-open-'.$view->getId().'"]');
    }
}
