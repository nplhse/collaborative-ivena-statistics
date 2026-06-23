<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SavedExplorerViewFavoriteServiceTest extends KernelTestCase
{
    use SeedsExplorerSystemViewsTrait;

    private SavedExplorerViewFavoriteService $service;

    private SavedExplorerViewRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->service = $container->get(SavedExplorerViewFavoriteService::class);
        $this->repository = $container->get(SavedExplorerViewRepository::class);
        $this->seedExplorerSystemViews();
    }

    public function testToggleFavoriteIsPerUser(): void
    {
        $userA = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $userB = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $view = $this->repository->findBySlug('gender-distribution');
        self::assertInstanceOf(SavedExplorerView::class, $view);

        self::assertTrue($this->service->toggle($userA, $view));
        self::assertTrue($this->service->isFavorite($userA, $view));
        self::assertFalse($this->service->isFavorite($userB, $view));

        self::assertFalse($this->service->toggle($userA, $view));
        self::assertFalse($this->service->isFavorite($userA, $view));
    }

    public function testListViewsForUserReturnsFavoritedViews(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $first = $this->repository->findBySlug('allocations-over-time');
        $second = $this->repository->findBySlug('urgency-over-time');
        self::assertInstanceOf(SavedExplorerView::class, $first);
        self::assertInstanceOf(SavedExplorerView::class, $second);

        $this->service->toggle($user, $first);
        $this->service->toggle($user, $second);

        $titles = array_map(static fn (SavedExplorerView $view): string => $view->getTitle(), $this->service->listViewsForUser($user));
        self::assertContains('Allocations over time', $titles);
        self::assertContains('Urgency over time', $titles);
    }
}
