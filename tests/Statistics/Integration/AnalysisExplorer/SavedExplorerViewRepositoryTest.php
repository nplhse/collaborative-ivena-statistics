<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SavedExplorerViewRepositoryTest extends KernelTestCase
{
    private SavedExplorerViewRepository $repository;

    private ExplorerSystemViewSeeder $seeder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->repository = $container->get(SavedExplorerViewRepository::class);
        $this->seeder = $container->get(ExplorerSystemViewSeeder::class);
    }

    public function testSaveAndFindBySlug(): void
    {
        $view = new SavedExplorerView(
            slug: 'test-view',
            title: 'Test view',
            category: 'Allocations',
            configJson: ['schemaVersion' => 1],
            description: 'Test description',
            isSystem: true,
        );
        $this->repository->save($view);

        $found = $this->repository->findBySlug('test-view');
        self::assertInstanceOf(SavedExplorerView::class, $found);
        self::assertSame('Test view', $found->getTitle());
        self::assertTrue($found->isSystem());
    }

    public function testSeederIsIdempotent(): void
    {
        $first = $this->seeder->sync();
        $second = $this->seeder->sync();

        self::assertSame(6, $first->created);
        self::assertSame(0, $first->updated);
        self::assertSame(0, $second->created);
        self::assertSame(0, $second->updated);
        self::assertSame(6, $second->skipped);

        $views = $this->repository->findAllSystemViewsOrdered();
        self::assertCount(6, $views);
        $slugs = array_map(static fn (SavedExplorerView $view): string => $view->getSlug(), $views);
        self::assertContains('allocations-over-time', $slugs);
        self::assertContains('urgency-over-time', $slugs);
    }
}
