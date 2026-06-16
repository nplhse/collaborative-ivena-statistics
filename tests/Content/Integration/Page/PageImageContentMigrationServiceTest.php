<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageImageContentMigrationService;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageImageContentMigrationServiceTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunDoesNotPersistSizeMigration(): void
    {
        $page = $this->createPageWithLargeImageBlock();
        $pageId = (int) $page->getId();

        $result = $this->migrationService()->migrateSizes(true, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame(1, $result->updatedPages);
        self::assertSame('lg', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    public function testApplyMigratesLargeImageBlocksToAuto(): void
    {
        $page = $this->createPageWithLargeImageBlock();
        $pageId = (int) $page->getId();

        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame('auto', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    public function testFixRichtextSnippetsReplacesLegacySizeClass(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-richtext',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame(1, $result->updatedPages);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
        self::assertStringNotContainsString(
            'page-content-image--size-lg',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
    }

    public function testMigrateSizesWithoutPageFilterUpdatesAllCandidates(): void
    {
        $page = $this->createPageWithLargeImageBlock();
        $pageId = (int) $page->getId();

        $result = $this->migrationService()->migrateSizes(false);

        self::assertGreaterThanOrEqual(1, $result->updatedBlocks);
        self::assertSame('auto', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    public function testFixRichtextSnippetsDryRunDoesNotPersist(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-richtext-dry-run',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(true, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertStringContainsString(
            'page-content-image--size-lg',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
    }

    public function testFixHighlightSnippetsReplacesLegacySizeClass(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-highlight',
            'content' => [
                [
                    'type' => 'highlight',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
    }

    public function testFixAccordionSnippetsReplacesLegacySizeClass(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-accordion',
            'content' => [
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            [
                                'title' => 'Item',
                                'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                            ],
                        ],
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['items'][0]['html'],
        );
    }

    public function testFixRichtextSnippetsWithoutPageFilterUpdatesAllPages(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-all-pages',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false);

        self::assertGreaterThanOrEqual(1, $result->updatedBlocks);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
    }

    public function testFixRichtextSnippetsSkipsBlocksWithoutHtmlChanges(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-no-snippet-change',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-auto"></figure>',
                    ],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => ''],
                ],
            ],
        ])->_real();

        $result = $this->migrationService()->fixRichtextSnippets(false, (int) $page->getId());

        self::assertSame(0, $result->updatedBlocks);
        self::assertSame(0, $result->updatedPages);
    }

    public function testMigrateSizesSkipsNonImageBlocks(): void
    {
        $media = MediaFactory::createOne([
            'filename' => 'migrate-with-headline.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'migration-headline-and-image',
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Title'],
                ],
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/migrate-with-headline.png',
                        'alt' => 'Image',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame('auto', $this->reloadPage($pageId)->getContent()[1]['data']['size']);
    }

    public function testMigrateSizesSkipsNonLargeImageBlocksOnCandidatePage(): void
    {
        $media = MediaFactory::createOne([
            'filename' => 'migrate-candidate-mix.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'migration-lg-and-md',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/migrate-candidate-mix.png',
                        'alt' => 'Large candidate',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/migrate-candidate-mix.png',
                        'alt' => 'Medium sibling',
                        'size' => 'md',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame('auto', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
        self::assertSame('md', $this->reloadPage($pageId)->getContent()[1]['data']['size']);
    }

    public function testFixRichtextSnippetsReturnsEmptyForUnknownPageId(): void
    {
        $result = $this->migrationService()->fixRichtextSnippets(false, 999_999);

        self::assertSame(0, $result->updatedBlocks);
        self::assertSame(0, $result->updatedPages);
    }

    public function testFixAccordionSnippetsSkipsInvalidItems(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-accordion-invalid',
            'content' => [
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            ['title' => 'Broken'],
                            [
                                'title' => 'Valid',
                                'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                            ],
                        ],
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['items'][1]['html'],
        );
    }

    public function testFixRichtextSnippetsSkipsBlocksWithoutHtmlField(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'migration-highlight-no-html',
            'content' => [
                [
                    'type' => 'highlight',
                    'enabled' => true,
                    'data' => ['variant' => 'info'],
                ],
            ],
        ])->_real();

        $result = $this->migrationService()->fixRichtextSnippets(false, (int) $page->getId());

        self::assertSame(0, $result->updatedBlocks);
    }

    public function testSkipsFloatedLargeImagesDuringSizeMigration(): void
    {
        $media = MediaFactory::createOne([
            'filename' => 'floated.png',
            'width' => 400,
            'height' => 300,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'migration-floated-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/floated.png',
                        'alt' => 'Floated',
                        'size' => 'lg',
                        'float' => 'left',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(0, $result->updatedBlocks);
        self::assertSame('lg', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    private function createPageWithLargeImageBlock(): \App\Content\Domain\Entity\Page
    {
        $media = MediaFactory::createOne([
            'filename' => 'migrate-me.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        return PageFactory::createOne([
            'slug' => 'migration-lg-image',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/migrate-me.png',
                        'alt' => 'Migrate me',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();
    }

    private function reloadPage(int $pageId): \App\Content\Domain\Entity\Page
    {
        $page = self::getContainer()->get(PageRepository::class)->find($pageId);
        self::assertNotNull($page);

        return $page;
    }

    private function migrationService(): PageImageContentMigrationService
    {
        return self::getContainer()->get(PageImageContentMigrationService::class);
    }
}
