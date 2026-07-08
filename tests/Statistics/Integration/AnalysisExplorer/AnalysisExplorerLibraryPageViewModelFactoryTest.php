<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerLibraryPageViewModelFactory;
use App\Statistics\AnalysisExplorer\UI\Http\Navigation\ExplorerLibraryQueryKeys;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AnalysisExplorerLibraryPageViewModelFactoryTest extends KernelTestCase
{
    use SeedsExplorerSystemViewsTrait;

    private AnalysisExplorerLibraryPageViewModelFactory $factory;

    private SavedExplorerViewRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(AnalysisExplorerLibraryPageViewModelFactory::class);
        $this->repository = self::getContainer()->get(SavedExplorerViewRepository::class);
        $this->seedExplorerSystemViews();
    }

    public function testCreateBuildsAllTabWithCategoryFiltersAndTranslatedCards(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $user);

        self::assertSame('all', $page->activeTab);
        self::assertNull($page->activeCategory);
        self::assertTrue($page->isLoggedIn);
        self::assertCount(3, $page->tabs);
        self::assertSame('all', $page->tabs[0]['key']);
        self::assertTrue($page->tabs[0]['active']);
        self::assertArrayNotHasKey('count', $page->tabs[0]);
        self::assertSame('favorites', $page->tabs[1]['key']);
        self::assertSame(0, $page->tabs[1]['count']);
        self::assertSame('my_views', $page->tabs[2]['key']);
        self::assertSame(0, $page->tabs[2]['count']);
        self::assertNotEmpty($page->categoryFilters);
        self::assertSame('', $page->categoryFilters[0]['key']);
        self::assertTrue($page->categoryFilters[0]['active']);
        self::assertSame('allocations', $page->categoryFilters[1]['key']);
        self::assertNotEmpty($page->cards);

        $cardsById = [];
        foreach ($page->cards as $card) {
            $cardsById[$card['id']] = $card;
        }

        $overTime = $this->repository->findBySlug('allocations-over-time');
        self::assertNotNull($overTime?->getId());
        $card = $cardsById[$overTime->getId()];

        self::assertSame('Time period', $card['dimension']);
        self::assertSame('Month', $card['grain']);
        self::assertSame('Line chart', $card['chartType']);
        self::assertSame('Allocations over time', $card['title']);
        self::assertTrue($card['isSystem']);
        self::assertSame('allocations', $card['categoryKey']);
        self::assertSame('Allocations', $card['categoryLabel']);
        self::assertStringContainsString('category=allocations', (string) $card['categoryUrl']);
        self::assertStringContainsString('/statistics/analysis/explorer/'.$overTime->getId(), $card['openUrl']);
    }

    public function testCreateFiltersSystemCardsByCategory(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=all&'.ExplorerLibraryQueryKeys::CATEGORY.'=allocations',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertSame('all', $page->activeTab);
        self::assertSame('allocations', $page->activeCategory);
        self::assertNotEmpty($page->cards);
        foreach ($page->cards as $card) {
            self::assertTrue($card['isSystem']);
            self::assertSame('allocations', $card['categoryKey']);
        }
    }

    public function testCreateBuildsEmptyFavoritesTabForUserWithoutFavorites(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=favorites',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertSame('favorites', $page->activeTab);
        self::assertSame([], $page->categoryFilters);
        self::assertSame([], $page->cards);
        self::assertSame(0, $page->tabs[1]['count']);
    }

    public function testCreateExposesTabCountsForFavoritesAndMyViews(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $favoriteService = self::getContainer()->get(SavedExplorerViewFavoriteService::class);
        $overTime = $this->repository->findBySlug('allocations-over-time');
        self::assertNotNull($overTime);
        $favoriteService->toggle($user, $overTime);

        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $user);

        self::assertSame(1, $page->tabs[1]['count']);
        self::assertSame(0, $page->tabs[2]['count']);
    }

    public function testCreateGuestOnlyShowsOverviewTabWithoutCounts(): void
    {
        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), null);

        self::assertFalse($page->isLoggedIn);
        self::assertSame('all', $page->activeTab);
        self::assertCount(1, $page->tabs);
        self::assertSame('all', $page->tabs[0]['key']);
        self::assertArrayNotHasKey('count', $page->tabs[0]);
    }

    public function testCreateFallsBackToOverviewForInvalidOrGuestOnlyTabs(): void
    {
        $guestFavorites = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=favorites',
                Request::METHOD_GET,
            ),
            null,
        );
        self::assertSame('all', $guestFavorites->activeTab);

        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $invalidTab = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=unknown',
                Request::METHOD_GET,
            ),
            $user,
        );
        self::assertSame('all', $invalidTab->activeTab);
    }

    public function testCreateSortsCardsAlphabeticallyByTitle(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $user);

        $titles = array_map(static fn (array $card): string => $card['title'], $page->cards);

        for ($index = 1, $count = \count($titles); $index < $count; ++$index) {
            self::assertLessThanOrEqual(0, strcasecmp($titles[$index - 1], $titles[$index]));
        }
    }

    public function testCreateFiltersCardsBySearchInTitleAndDescription(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::SEARCH.'=gender',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertSame('gender', $page->searchQuery);
        self::assertNotEmpty($page->cards);
        foreach ($page->cards as $card) {
            $haystack = mb_strtolower($card['title']."\n".$card['description']);
            self::assertStringContainsString('gender', $haystack);
        }

        $noMatch = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::SEARCH.'=zzznomatchzzz',
                Request::METHOD_GET,
            ),
            $user,
        );
        self::assertSame([], $noMatch->cards);
    }

    public function testCreatePreservesSearchInTabUrls(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::SEARCH.'=tier',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertStringContainsString(ExplorerLibraryQueryKeys::SEARCH.'=tier', $page->tabs[1]['url']);
    }
}
