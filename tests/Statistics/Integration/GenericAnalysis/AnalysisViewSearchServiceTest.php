<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\AnalysisViewSearchService;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnalysisViewSearchServiceTest extends KernelTestCase
{
    private AnalysisViewSearchService $searchService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchService = self::getContainer()->get(AnalysisViewSearchService::class);
    }

    public function testSearchMatchesTitleSubstring(): void
    {
        $views = $this->searchService->search('resus');

        self::assertNotEmpty($views);
        self::assertTrue(
            array_any($views, static fn (\App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition $view): bool => str_contains(strtolower((string) $view->title), 'resus')
                || array_any($view->tags, static fn (string $tag): bool => str_contains(strtolower($tag), 'resus'))),
        );
    }

    public function testSearchFiltersByCategory(): void
    {
        $views = $this->searchService->search(null, AnalysisViewCategory::Clinical);

        self::assertNotEmpty($views);
        foreach ($views as $view) {
            self::assertSame(AnalysisViewCategory::Clinical, $view->category);
        }
        self::assertFalse(
            array_any($views, static fn (\App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition $view): bool => 'allocations_by_month' === $view->key),
        );
    }

    public function testSearchFiltersByTag(): void
    {
        $views = $this->searchService->search(null, null, 'resus');

        self::assertNotEmpty($views);
        foreach ($views as $view) {
            self::assertContains('resus', array_map(strtolower(...), $view->tags));
        }
    }
}
