<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerLibraryPageViewModelFactory;
use App\Statistics\AnalysisExplorer\UI\Http\Navigation\ExplorerLibraryQueryKeys;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Entity\User;
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
        foreach ($this->cardsAcrossPages($user, '/statistics/analysis/library') as $card) {
            $cardsById[$card['id']] = $card;
        }

        $overTime = $this->repository->findBySlug('allocations-over-time');
        self::assertNotNull($overTime?->getId());
        $card = $cardsById[$overTime->getId()];

        self::assertSame('Allocations', $card['dimension']);
        self::assertSame('Month', $card['grain']);
        self::assertSame('Line chart', $card['chartType']);
        self::assertSame('Allocations over time', $card['title']);
        self::assertTrue($card['isSystem']);
        self::assertNull($card['authorName']);
        self::assertSame(
            $overTime->getUpdatedAt() > $overTime->getCreatedAt() ? 'updated' : 'created',
            $card['activityKind'],
        );
        self::assertNotSame('', $card['activityIso']);
        self::assertSame('allocations', $card['categoryKey']);
        self::assertSame('Allocations', $card['categoryLabel']);
        self::assertStringContainsString('category=allocations', (string) $card['categoryUrl']);
        self::assertStringContainsString('dimension=time', (string) $card['dimensionUrl']);
        self::assertStringContainsString('grain=month', (string) $card['grainUrl']);
        self::assertStringContainsString('chart=line', (string) $card['chartUrl']);
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
        self::assertSame('', $page->categoryFilters[0]['key']);
        self::assertCount(1, $page->categoryFilters);
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

        $guestPublic = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=public',
                Request::METHOD_GET,
            ),
            null,
        );
        self::assertSame('all', $guestPublic->activeTab);

        $member = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $memberPage = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $member);
        self::assertCount(3, $memberPage->tabs);
        self::assertSame(['all', 'favorites', 'my_views'], array_column($memberPage->tabs, 'key'));
    }

    public function testOverviewMixesOwnViewsAndOtherParticipantsPublicViews(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $viewer = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $config = $this->repository->findBySlug('allocations-over-time')?->getConfigJson() ?? [];
        $hospitalConfig = $config;
        $hospitalConfig['dataSource'] = 'hospitals';

        $publicView = new SavedExplorerView(
            slug: null,
            title: 'Shared allocations',
            category: 'My views',
            configJson: $config,
            isSystem: false,
            visibility: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Public,
        );
        $publicView->setCreatedBy($owner);
        $this->repository->save($publicView);

        $ownPublic = new SavedExplorerView(
            slug: null,
            title: 'Own public hospitals',
            category: 'My views',
            configJson: $hospitalConfig,
            isSystem: false,
            visibility: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Public,
        );
        $ownPublic->setCreatedBy($viewer);
        $this->repository->save($ownPublic);

        $privateView = new SavedExplorerView(
            slug: null,
            title: 'Still private',
            category: 'My views',
            configJson: $config,
            isSystem: false,
        );
        $privateView->setCreatedBy($owner);
        $this->repository->save($privateView);

        $cards = $this->cardsAcrossPages($viewer, '/statistics/analysis/library');
        $titles = array_column($cards, 'title');

        self::assertContains('Shared allocations', $titles);
        self::assertContains('Own public hospitals', $titles);
        self::assertNotContains('Still private', $titles);
        self::assertContains('Allocations over time', $titles);

        $shared = $cards[array_search('Shared allocations', $titles, true)];
        self::assertTrue($shared['isPublic']);
        self::assertSame($owner->getUsername(), $shared['authorName']);
        self::assertSame('allocations', $shared['categoryKey']);

        $allocationTitles = array_column(
            $this->cardsAcrossPages(
                $viewer,
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=all&'.ExplorerLibraryQueryKeys::CATEGORY.'=allocations',
            ),
            'title',
        );
        self::assertContains('Shared allocations', $allocationTitles);
        self::assertNotContains('Own public hospitals', $allocationTitles);

        $myViews = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=my_views',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        self::assertSame(['Own public hospitals'], array_column($myViews->cards, 'title'));
    }

    public function testOverviewFiltersByOriginAndUsername(): void
    {
        $owner = UserFactory::createOne([
            'username' => 'origin-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $viewer = UserFactory::createOne([
            'username' => 'origin-viewer',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $config = $this->repository->findBySlug('allocations-over-time')?->getConfigJson() ?? [];
        $hospitalConfig = $config;
        $hospitalConfig['dataSource'] = 'hospitals';

        $publicView = new SavedExplorerView(
            slug: null,
            title: 'Shared by owner',
            category: 'My views',
            configJson: $config,
            isSystem: false,
            visibility: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Public,
        );
        $publicView->setCreatedBy($owner);
        $this->repository->save($publicView);

        $ownPublic = new SavedExplorerView(
            slug: null,
            title: 'Public by viewer',
            category: 'My views',
            configJson: $hospitalConfig,
            isSystem: false,
            visibility: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Public,
        );
        $ownPublic->setCreatedBy($viewer);
        $this->repository->save($ownPublic);

        $system = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::ORIGIN.'=system',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        $systemTitles = array_column(
            $this->cardsAcrossPages($viewer, '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::ORIGIN.'=system'),
            'title',
        );
        self::assertSame('system', $system->origin);
        self::assertContains('Allocations over time', $systemTitles);
        self::assertNotContains('Shared by owner', $systemTitles);
        self::assertNotContains('Public by viewer', $systemTitles);
        foreach ($system->cards as $card) {
            self::assertTrue($card['isSystem']);
        }
        self::assertSame(['all', 'system', 'public'], array_column($system->originFilters, 'key'));
        self::assertTrue($system->originFilters[1]['active']);
        self::assertStringNotContainsString(ExplorerLibraryQueryKeys::ORIGIN.'=', $system->originFilters[0]['url']);
        self::assertStringContainsString(ExplorerLibraryQueryKeys::ORIGIN.'=public', $system->originFilters[2]['url']);

        $public = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::ORIGIN.'=public',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        $publicTitles = array_column($public->cards, 'title');
        self::assertContains('Shared by owner', $publicTitles);
        self::assertContains('Public by viewer', $publicTitles);
        self::assertNotContains('Allocations over time', $publicTitles);

        $byOwner = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::USER.'=origin-own',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        self::assertSame('origin-own', $byOwner->userQuery);
        $ownerTitles = array_column($byOwner->cards, 'title');
        self::assertContains('Shared by owner', $ownerTitles);
        self::assertNotContains('Public by viewer', $ownerTitles);
        self::assertNotContains('Allocations over time', $ownerTitles);
        self::assertStringContainsString(ExplorerLibraryQueryKeys::USER.'=origin-own', $byOwner->categoryFilters[0]['url']);

        $myViews = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=my_views&'.ExplorerLibraryQueryKeys::ORIGIN.'=system&'.ExplorerLibraryQueryKeys::USER.'=origin-owner',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        self::assertSame([], $myViews->cards);
        self::assertSame(['all', 'system', 'public'], array_column($myViews->originFilters, 'key'));

        $ownViews = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=my_views',
                Request::METHOD_GET,
            ),
            $viewer,
        );
        self::assertSame(['Public by viewer'], array_column($ownViews->cards, 'title'));
    }

    public function testFacetFiltersCombineAndApplyOnFavorites(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $favoriteService = self::getContainer()->get(SavedExplorerViewFavoriteService::class);
        $line = $this->repository->findBySlug('allocations-over-time');
        $bars = $this->repository->findBySlug('gender-distribution');
        self::assertNotNull($line);
        self::assertNotNull($bars);
        $favoriteService->toggle($user, $line);
        $favoriteService->toggle($user, $bars);

        $chart = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::CHART.'=bar&'.ExplorerLibraryQueryKeys::CATEGORY.'=allocations',
                Request::METHOD_GET,
            ),
            $user,
        );
        $chartIds = array_column(
            $this->cardsAcrossPages(
                $user,
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::CHART.'=bar&'.ExplorerLibraryQueryKeys::CATEGORY.'=allocations',
            ),
            'id',
        );
        self::assertContains($bars->getId(), $chartIds);
        self::assertNotContains($line->getId(), $chartIds);
        foreach ($chart->cards as $card) {
            self::assertSame('bar', $card['chartTypeKey']);
            self::assertSame('allocations', $card['categoryKey']);
        }
        self::assertFalse($chart->chartFilters[0]['active']);
        $barFilter = $chart->chartFilters[array_search('bar', array_column($chart->chartFilters, 'key'), true)];
        self::assertTrue($barFilter['active']);
        self::assertStringContainsString(ExplorerLibraryQueryKeys::CATEGORY.'=allocations', $barFilter['url']);
        self::assertSame([
            ['label' => 'Data source', 'value' => 'Allocations'],
            ['label' => 'Chart', 'value' => 'Bar chart'],
        ], $chart->activeFilterBadges);

        $dimension = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::DIMENSION.'=gender&'.ExplorerLibraryQueryKeys::CHART.'=bar',
                Request::METHOD_GET,
            ),
            $user,
        );
        foreach ($dimension->cards as $card) {
            self::assertSame('gender', $card['dimensionKey']);
            self::assertSame('bar', $card['chartTypeKey']);
        }
        self::assertContains($bars->getId(), array_column($dimension->cards, 'id'));

        $favorites = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=favorites&'.ExplorerLibraryQueryKeys::CHART.'=line',
                Request::METHOD_GET,
            ),
            $user,
        );
        self::assertSame([$line->getId()], array_column($favorites->cards, 'id'));
        self::assertSame('line', $favorites->cards[0]['chartTypeKey']);
        self::assertSame('time', $favorites->cards[0]['dimensionKey']);
        self::assertSame('month', $favorites->cards[0]['grainKey']);
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

    public function testSwitchingLibraryTabsDropsFilters(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'
                .ExplorerLibraryQueryKeys::SEARCH.'=tier&'
                .ExplorerLibraryQueryKeys::CATEGORY.'=allocations&'
                .ExplorerLibraryQueryKeys::ORIGIN.'=system&'
                .ExplorerLibraryQueryKeys::USER.'=admin&'
                .ExplorerLibraryQueryKeys::DIMENSION.'=time&'
                .ExplorerLibraryQueryKeys::CHART.'=line&'
                .ExplorerLibraryQueryKeys::GRAIN.'=month&'
                .ExplorerLibraryQueryKeys::PAGE.'=2&'
                .'scope=public&period=all',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertCount(3, $page->tabs);
        foreach ($page->tabs as $tab) {
            self::assertStringContainsString(ExplorerLibraryQueryKeys::TAB.'='.$tab['key'], $tab['url']);
            self::assertStringContainsString('scope=public', $tab['url']);
            self::assertStringContainsString('period=all', $tab['url']);
            foreach ([
                ExplorerLibraryQueryKeys::SEARCH,
                ExplorerLibraryQueryKeys::CATEGORY,
                ExplorerLibraryQueryKeys::ORIGIN,
                ExplorerLibraryQueryKeys::USER,
                ExplorerLibraryQueryKeys::DIMENSION,
                ExplorerLibraryQueryKeys::CHART,
                ExplorerLibraryQueryKeys::GRAIN,
                ExplorerLibraryQueryKeys::PAGE,
            ] as $filterKey) {
                self::assertStringNotContainsString($filterKey.'=', $tab['url']);
            }
        }
    }

    public function testCreateLabelsDayGrainOnUserViews(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = new SavedExplorerView(
            slug: null,
            title: 'Daily allocations',
            category: 'My views',
            configJson: [
                'schemaVersion' => 4,
                'query' => [
                    'rows' => ['dimension' => 'time', 'grain' => 'day'],
                ],
                'presentation' => ['chartType' => 'line'],
            ],
            isSystem: false,
        );
        $view->setCreatedBy($user);
        $this->repository->save($view);

        $page = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::TAB.'=my_views',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertContains(
            'Daily allocations',
            array_column($this->cardsAcrossPages($user, '/statistics/analysis/library'), 'title'),
        );
        self::assertCount(1, $page->cards);
        self::assertSame('Day', $page->cards[0]['grain']);
        self::assertSame('Daily allocations', $page->cards[0]['title']);
        self::assertFalse($page->cards[0]['isPublic']);
        self::assertSame($user->getUsername(), $page->cards[0]['authorName']);
        self::assertSame(
            $view->getUpdatedAt() > $view->getCreatedAt() ? 'updated' : 'created',
            $page->cards[0]['activityKind'],
        );
    }

    public function testLibraryShowsTenCardsPerPage(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $first = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $user);

        self::assertCount(10, $first->cards);
        self::assertTrue($first->hasToPaginate);
        self::assertSame(1, $first->currentPage);
        self::assertSame(1, $first->resultFrom);
        self::assertSame(10, $first->resultTo);
        self::assertGreaterThan(10, $first->resultTotal);
        self::assertGreaterThan(1, $first->lastPage);
        self::assertStringNotContainsString(ExplorerLibraryQueryKeys::PAGE.'=', $first->tabs[0]['url']);

        $second = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::PAGE.'=2',
                Request::METHOD_GET,
            ),
            $user,
        );

        self::assertSame(2, $second->currentPage);
        self::assertNotEmpty($second->cards);
        self::assertLessThanOrEqual(10, \count($second->cards));
        self::assertSame(11, $second->resultFrom);
        self::assertSame(10 + \count($second->cards), $second->resultTo);
        self::assertSame($first->resultTotal, $second->resultTotal);
        self::assertSame([], array_intersect(
            array_column($first->cards, 'id'),
            array_column($second->cards, 'id'),
        ));

        $beyond = $this->factory->create(
            Request::create(
                '/statistics/analysis/library?'.ExplorerLibraryQueryKeys::PAGE.'=99',
                Request::METHOD_GET,
            ),
            $user,
        );
        self::assertSame($first->lastPage, $beyond->currentPage);
        self::assertNotEmpty($beyond->cards);
    }

    public function testSearchWithNoMatchesReportsAnEmptyRange(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $page = $this->factory->create(
            Request::create('/statistics/analysis/library?'.ExplorerLibraryQueryKeys::SEARCH.'=zzz-no-such-view'),
            $user,
        );

        self::assertSame([], $page->cards);
        self::assertSame(0, $page->resultFrom);
        self::assertSame(0, $page->resultTo);
        self::assertSame(0, $page->resultTotal);
        self::assertSame(1, $page->lastPage);
        self::assertFalse($page->hasToPaginate);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardsAcrossPages(User $viewer, string $path): array
    {
        $cards = [];
        $pageNumber = 1;
        do {
            $separator = str_contains($path, '?') ? '&' : '?';
            $page = $this->factory->create(
                Request::create($path.$separator.ExplorerLibraryQueryKeys::PAGE.'='.$pageNumber, Request::METHOD_GET),
                $viewer,
            );
            array_push($cards, ...$page->cards);
            ++$pageNumber;
        } while ($page->hasToPaginate && $pageNumber <= $page->lastPage);

        return $cards;
    }
}
