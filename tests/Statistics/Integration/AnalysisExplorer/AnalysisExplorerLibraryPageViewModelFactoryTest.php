<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerLibraryPageViewModelFactory;
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

    public function testCreateBuildsTranslatedCardMetadataWithIdUrls(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET), $user);

        self::assertCount(3, $page->sections);
        self::assertSame('favorites', $page->sections[0]['key']);
        self::assertSame('my_views', $page->sections[1]['key']);
        self::assertSame('system', $page->sections[2]['key']);

        $systemSection = $page->sections[2];
        self::assertCount(1, $systemSection['categories']);
        self::assertSame('allocations', $systemSection['categories'][0]['key']);

        $cardsById = [];
        foreach ($systemSection['categories'][0]['cards'] as $card) {
            $cardsById[$card['id']] = $card;
        }

        $overTime = $this->repository->findBySlug('allocations-over-time');
        self::assertNotNull($overTime?->getId());
        $card = $cardsById[$overTime->getId()];

        self::assertSame('Total allocations', $card['dimension']);
        self::assertSame('Month', $card['grain']);
        self::assertSame('Bar chart', $card['chartType']);
        self::assertTrue($card['isSystem']);
        self::assertStringContainsString('/statistics/analysis/explorer/'.$overTime->getId(), $card['openUrl']);
    }
}
