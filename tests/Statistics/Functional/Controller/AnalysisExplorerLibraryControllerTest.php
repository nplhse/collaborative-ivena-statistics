<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-library-tab-public"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-filters"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-library-active-filters"]');
        $this->assertSelectorExists('option[data-testid="stats-analysis-explorer-library-category-all"][selected]');
        $this->assertSelectorExists('select[data-testid="stats-analysis-explorer-library-category-filters"]');
        $this->assertSelectorExists('option[data-testid="stats-analysis-explorer-library-category-allocations"]');
        $this->assertSelectorExists('select[data-testid="stats-analysis-explorer-library-origin-filters"]');
        $this->assertSelectorExists('select[data-testid="stats-analysis-explorer-library-chart-filters"]');
        $this->assertSelectorExists('select[data-testid="stats-analysis-explorer-library-grain-filters"]');
        $this->assertSelectorExists('select[data-testid="stats-analysis-explorer-library-dimension-filters"] option[value="time"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-library-result-range"]',
            '1–10 of ',
        );
        $cardSelector = '[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]';
        $this->openPageContainingCard($client, $client->getCrawler(), $cardSelector);
        $this->assertSelectorExists($cardSelector);
        $this->assertSelectorTextContains($cardSelector, 'Allocations');
        $this->assertSelectorExists($cardSelector.' .card-header.card-header-light h3.card-title a.link-primary[data-testid="stats-analysis-explorer-open-'.$view->getId().'"]');
        $this->assertSelectorExists($cardSelector.' .card-header [data-testid="stats-analysis-explorer-favorite-'.$view->getId().'"]');
        $this->assertSelectorNotExists('.btn[data-testid="stats-analysis-explorer-open-'.$view->getId().'"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-pagination"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-page-2"]');
        $this->assertSelectorExists($cardSelector.' .card-body [data-testid="stats-analysis-explorer-category-badge-allocations"]');
        $this->assertSelectorExists($cardSelector.' .card-body a[data-testid="stats-analysis-explorer-dimension-badge-time"][href*="dimension=time"]');
        $this->assertSelectorExists($cardSelector.' a[data-testid="stats-analysis-explorer-grain-badge-month"][href*="grain=month"]');
        $this->assertSelectorExists($cardSelector.' a[data-testid="stats-analysis-explorer-chart-badge-line"][href*="chart=line"]');
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
        $this->assertSelectorExists('option[data-testid="stats-analysis-explorer-library-category-allocations"][selected]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-library-active-filters"]',
            'Data source',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-library-active-filters"]',
            'Allocations',
        );
        $crawler = $this->openPageContainingCard(
            $client,
            $crawler,
            '[data-testid="stats-analysis-explorer-view-card-'.$overTime->getId().'"]',
        );
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

    public function testSystemViewTitleIsLocalizedInGerman(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();

        $view = self::getContainer()->get(SavedExplorerViewRepository::class)->findBySlug('allocations-over-time');
        self::assertNotNull($view?->getId());

        $client->followRedirects(true);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/locale/switch/de?_target_path=/statistics/analysis/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $selector = '[data-testid="stats-analysis-explorer-view-card-'.$view->getId().'"]';
        $this->openPageContainingCard($client, $crawler, $selector);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains($selector, 'Zuweisungen im Zeitverlauf');

        $client->request(Request::METHOD_GET, '/statistics/analysis/library?category=hospitals');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-category-badge-hospitals"]',
            'Kliniken',
        );
    }

    public function testPrivateViewLockExplainsThatOnlyTheOwnerCanSeeIt(): void
    {
        $client = self::createClient();
        $owner = $this->loginAsParticipant($client);
        $viewId = $this->persistPrivateView($owner, 'Private hint');

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all&search=Private+hint',
        );

        $this->assertResponseIsSuccessful();
        $lock = $crawler->filter('[data-testid="stats-analysis-explorer-private-lock-'.$viewId.'"]');
        self::assertCount(1, $lock);
        self::assertSame('Private view that only its owner can see.', $lock->attr('title'));
    }

    public function testLibraryShowsAssistantEntryWithCurrentScope(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/library?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-entry"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-entry"]',
            'Which analysis fits my question?',
        );
        $link = $client->getCrawler()->filter('[data-testid="stats-analysis-explorer-assistant-entry-link"]');
        self::assertGreaterThan(0, $link->count());
        $href = (string) $link->attr('href');
        self::assertStringContainsString('/statistics/analysis/assistant', $href);
        self::assertStringContainsString('scope=public', $href);
        self::assertStringNotContainsString('period=', $href);
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-search-input"]');
    }

    private function openPageContainingCard(KernelBrowser $client, Crawler $crawler, string $selector): Crawler
    {
        $page = 1;
        while (0 === $crawler->filter($selector)->count()) {
            $next = $crawler->filter('a[data-testid="stats-analysis-explorer-library-page-'.($page + 1).'"]');
            self::assertGreaterThan(0, $next->count(), 'Expected another library page while looking for '.$selector);
            $crawler = $client->click($next->link());
            ++$page;
            self::assertLessThan(15, $page);
        }

        return $crawler;
    }

    private function persistPrivateView(User $owner, string $title): int
    {
        $view = new SavedExplorerView(
            slug: null,
            title: $title,
            category: 'My views',
            configJson: ['schemaVersion' => 4, 'title' => $title],
            isSystem: false,
            visibility: AnalysisViewVisibility::Private,
        );
        $view->setCreatedBy($owner);
        $repository = self::getContainer()->get(SavedExplorerViewRepository::class);
        $repository->save($view);
        $id = $view->getId();
        self::assertNotNull($id);

        return $id;
    }
}
