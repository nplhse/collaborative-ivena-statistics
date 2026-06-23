<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Factory\UserFactory;
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

        UserFactory::createOne([
            'username' => 'admin',
            'roles' => ['ROLE_USER', 'ROLE_ADMIN'],
        ]);
    }

    public function testSaveAndFindBySlug(): void
    {
        $admin = UserFactory::find(['username' => 'admin']);
        $view = new SavedExplorerView(
            slug: 'test-view',
            title: 'Test view',
            category: 'Allocations',
            configJson: ['schemaVersion' => 1],
            description: 'Test description',
            isSystem: true,
        );
        $view->setCreatedBy($admin);
        $this->repository->save($view);

        $found = $this->repository->findBySlug('test-view');
        self::assertInstanceOf(SavedExplorerView::class, $found);
        self::assertSame('Test view', $found->getTitle());
        self::assertTrue($found->isSystem());
        self::assertTrue($found->wasCreatedBy($admin));
    }

    public function testFindByCreatorOrderedReturnsOnlyUserViewsForCreator(): void
    {
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $ownView = new SavedExplorerView(
            slug: null,
            title: 'My custom view',
            category: 'My views',
            configJson: ['schemaVersion' => 1],
            isSystem: false,
        );
        $ownView->setCreatedBy($creator);
        $this->repository->save($ownView);

        $foreignView = new SavedExplorerView(
            slug: null,
            title: 'Foreign view',
            category: 'My views',
            configJson: ['schemaVersion' => 1],
            isSystem: false,
        );
        $foreignView->setCreatedBy($other);
        $this->repository->save($foreignView);

        $results = $this->repository->findByCreatorOrdered($creator);
        self::assertCount(1, $results);
        self::assertSame('My custom view', $results[0]->getTitle());
    }

    public function testSeederIsIdempotent(): void
    {
        $first = $this->seeder->sync();
        $second = $this->seeder->sync();

        self::assertSame(14, $first->created);
        self::assertSame(0, $first->updated);
        self::assertSame(0, $second->created);
        self::assertSame(0, $second->updated);
        self::assertSame(14, $second->skipped);

        $views = $this->repository->findAllSystemViewsOrdered();
        self::assertCount(14, $views);
        $admin = UserFactory::find(['username' => 'admin']);
        foreach ($views as $view) {
            self::assertTrue($view->wasCreatedBy($admin));
        }
        $slugs = array_map(static fn (SavedExplorerView $view): ?string => $view->getSlug(), $views);
        self::assertContains('allocations-over-time', $slugs);
        self::assertContains('urgency-over-time', $slugs);
    }
}
