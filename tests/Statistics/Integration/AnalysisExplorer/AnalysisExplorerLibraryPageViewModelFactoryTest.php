<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerLibraryPageViewModelFactory;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AnalysisExplorerLibraryPageViewModelFactoryTest extends KernelTestCase
{
    use SeedsExplorerSystemViewsTrait;

    private AnalysisExplorerLibraryPageViewModelFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(AnalysisExplorerLibraryPageViewModelFactory::class);
        $this->seedExplorerSystemViews();
    }

    public function testCreateBuildsTranslatedCardMetadata(): void
    {
        $page = $this->factory->create(Request::create('/statistics/analysis/library', Request::METHOD_GET));

        self::assertCount(1, $page->categories);
        self::assertSame('allocations', $page->categories[0]['key']);

        $cardsBySlug = [];
        foreach ($page->categories[0]['cards'] as $card) {
            $cardsBySlug[$card['slug']] = $card;
        }

        self::assertSame('Total allocations', $cardsBySlug['allocations-over-time']['dimension']);
        self::assertSame('Month', $cardsBySlug['allocations-over-time']['grain']);
        self::assertSame('Bar chart', $cardsBySlug['allocations-over-time']['chartType']);

        self::assertSame('Gender', $cardsBySlug['gender-distribution']['dimension']);
        self::assertSame('Total (entire period)', $cardsBySlug['gender-distribution']['grain']);

        self::assertSame('Grouped bar', $cardsBySlug['gender-over-time']['chartType']);
        self::assertStringContainsString('/statistics/analysis/explorer/gender-over-time', $cardsBySlug['gender-over-time']['openUrl']);
    }
}
