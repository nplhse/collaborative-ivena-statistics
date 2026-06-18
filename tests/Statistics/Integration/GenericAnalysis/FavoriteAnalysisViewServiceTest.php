<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\FavoriteAnalysisViewService;
use App\Statistics\GenericAnalysis\Application\SavedAnalysisViewService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FavoriteAnalysisViewServiceTest extends KernelTestCase
{
    use Factories;

    private FavoriteAnalysisViewService $service;

    private SavedAnalysisViewService $savedViewService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->service = $container->get(FavoriteAnalysisViewService::class);
        $this->savedViewService = $container->get(SavedAnalysisViewService::class);
    }

    public function testToggleSystemViewAddsAndRemovesFavorite(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        self::assertTrue($this->service->toggleSystemView($user, 'allocations_by_month'));
        self::assertTrue($this->service->isSystemFavorite($user, 'allocations_by_month'));

        self::assertFalse($this->service->toggleSystemView($user, 'allocations_by_month'));
        self::assertFalse($this->service->isSystemFavorite($user, 'allocations_by_month'));
    }

    public function testToggleSavedViewAddsAndRemovesFavorite(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $saved = $this->savedViewService->create(
            owner: $user,
            title: 'Favorite saved view',
            config: new AnalysisViewConfig(primaryDimensionKey: 'month'),
        );

        self::assertTrue($this->service->toggleSavedView($user, $saved));
        self::assertTrue($this->service->isSavedFavorite($user, $saved));

        self::assertFalse($this->service->toggleSavedView($user, $saved));
        self::assertFalse($this->service->isSavedFavorite($user, $saved));
    }

    public function testListForUserReturnsOrderedFavorites(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $this->service->toggleSystemView($user, 'allocations_by_month');
        $this->service->toggleSystemView($user, 'urgency_by_month');

        $favorites = $this->service->listForUser($user);

        self::assertCount(2, $favorites);
        self::assertSame('allocations_by_month', $favorites[0]->getSystemViewKey());
        self::assertSame('urgency_by_month', $favorites[1]->getSystemViewKey());
        self::assertLessThan($favorites[1]->getSortOrder(), $favorites[0]->getSortOrder());
    }
}
