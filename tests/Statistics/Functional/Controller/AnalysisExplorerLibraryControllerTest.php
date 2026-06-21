<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

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

    public function testLibraryRendersDemoViewsGroupedByCategory(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-category-allocations"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-allocations-over-time"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-view-card-allocations-over-time"]',
            'Total allocations',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-gender-over-time"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-view-card-urgency-over-time"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-open-allocations-by-year"]');
    }
}
