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
        self::assertSame('allocation_count', $view->getConfigJson()['query']['metric'] ?? null);
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
