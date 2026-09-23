<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\AnalysisExplorer\Domain\Exception\SavedExplorerViewForbiddenException;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Domain\Entity\SavedExplorerViewFavorite;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewFavoriteRepository;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SavedExplorerViewServiceTest extends KernelTestCase
{
    use SeedsExplorerSystemViewsTrait;

    private SavedExplorerViewService $service;

    private SavedExplorerViewRepository $repository;

    private ExplorerConfigMapper $configMapper;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->service = $container->get(SavedExplorerViewService::class);
        $this->repository = $container->get(SavedExplorerViewRepository::class);
        $this->configMapper = $container->get(ExplorerConfigMapper::class);
        $this->seedExplorerSystemViews();
    }

    public function testCreatePersistsUserViewWithoutSlug(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = $this->defaultState();

        $view = $this->service->create($user, 'My allocations view', $state, 'Personal copy');

        self::assertNotNull($view->getId());
        self::assertNull($view->getSlug());
        self::assertFalse($view->isSystem());
        self::assertSame('My allocations view', $view->getTitle());
        self::assertSame('allocation_count', $view->getConfigJson()['query']['visualMetric'] ?? null);
        self::assertSame(['allocation_count'], $view->getConfigJson()['query']['metrics'] ?? null);
        self::assertSame(AnalysisViewVisibility::Private, $view->getVisibility());
    }

    public function testCreatePersistsChosenVisibility(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $view = $this->service->create(
            $user,
            'Shared allocations',
            $this->defaultState(),
            null,
            null,
            AnalysisViewVisibility::Public,
        );

        self::assertSame(AnalysisViewVisibility::Public, $view->getVisibility());
        self::assertSame('Shared allocations', $view->getConfigJson()['title'] ?? null);
    }

    public function testUpdateCanChangeVisibilityWithoutDroppingTheTitle(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = $this->service->create($user, 'Still private', $this->defaultState());
        $view->setCreatedBy($user);
        $this->repository->save($view);

        $updated = $this->service->update(
            $view,
            $user,
            'Now shared',
            $this->defaultState(),
            'Visible to participants',
            AnalysisViewVisibility::Public,
        );

        self::assertSame(AnalysisViewVisibility::Public, $updated->getVisibility());
        self::assertSame('Now shared', $updated->getTitle());
        self::assertSame('Now shared', $updated->getConfigJson()['title'] ?? null);
    }

    public function testDeleteRemovesAPublicViewAndItsFavoritesForTheOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = $this->service->create(
            $owner,
            'Public and gone',
            $this->defaultState(),
            null,
            null,
            AnalysisViewVisibility::Public,
        );
        $view->setCreatedBy($owner);
        $this->repository->save($view);
        $viewId = $view->getId();
        self::assertNotNull($viewId);

        $favorites = self::getContainer()->get(SavedExplorerViewFavoriteRepository::class);
        $favorite = new SavedExplorerViewFavorite($owner, $view);
        $favorites->save($favorite);
        $favoriteId = $favorite->getId();

        $this->service->delete($view, $owner);

        self::assertNull($this->repository->find($viewId));
        self::assertNull($favorites->find($favoriteId));
    }

    public function testDeleteRejectsAnotherUser(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = $this->service->create($owner, 'Mine', $this->defaultState());
        $view->setCreatedBy($owner);
        $this->repository->save($view);

        $this->expectException(SavedExplorerViewForbiddenException::class);
        $this->service->delete($view, $other);
    }

    public function testDeleteRejectsSystemView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $systemView = $this->repository->findBySlug('allocations-over-time');
        self::assertInstanceOf(SavedExplorerView::class, $systemView);

        $this->expectException(SavedExplorerViewForbiddenException::class);
        $this->service->delete($systemView, $user);
    }

    public function testUpdateAllowsCreatorToPersistChanges(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = $this->service->create($user, 'Editable view', $this->defaultState());
        $view->setCreatedBy($user);
        $this->repository->save($view);

        $state = $this->defaultState();
        $state['presentation']['chartType'] = 'line';

        $updated = $this->service->update($view, $user, 'Editable view renamed', $state, 'Updated');

        self::assertSame('line', $updated->getConfigJson()['presentation']['chartType'] ?? null);
        self::assertSame('Updated', $updated->getDescription());
    }

    public function testUpdateRejectsSystemView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $systemView = $this->repository->findBySlug('allocations-over-time');
        self::assertInstanceOf(SavedExplorerView::class, $systemView);

        $this->expectException(SavedExplorerViewForbiddenException::class);
        $this->service->update($systemView, $user, 'Nope', $this->defaultState());
    }

    public function testSetVisibilityIsOwnerOnlyAndSaveAsStaysPrivate(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = $this->service->create($owner, 'Shared later', $this->defaultState());
        $view->setCreatedBy($owner);
        $this->repository->save($view);

        $this->service->setVisibility($view, $owner, AnalysisViewVisibility::Public);
        self::assertTrue($view->isPublic());

        $copy = $this->service->create($other, 'Private copy', $view->getConfigJson());
        self::assertSame(AnalysisViewVisibility::Private, $copy->getVisibility());

        $this->expectException(SavedExplorerViewForbiddenException::class);
        $this->service->setVisibility($view, $other, AnalysisViewVisibility::Private);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultState(): array
    {
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        return $this->configMapper->toStateArray(
            self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($filter),
        );
    }
}
