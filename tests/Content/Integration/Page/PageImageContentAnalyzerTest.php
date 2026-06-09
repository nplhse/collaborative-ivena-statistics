<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageImageContentAnalyzer;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Path;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageImageContentAnalyzerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRecommendsMigratingSmallFullWidthImageToAuto(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'small-image.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-small-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/small-image.png',
                        'alt' => 'Small',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('Migrate size lg to auto', $findings[0]->recommendation);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(605, $findings[0]->imageWidth);
    }

    public function testAutoSizeImageNeedsNoChange(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'auto-image.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-auto',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/auto-image.png',
                        'alt' => 'Auto',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testMissingLocalFileIsReported(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'missing-on-disk.png',
            'width' => 400,
            'height' => 300,
        ])->_real();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $path = Path::join($projectDir, 'public', 'uploads', 'media', 'missing-on-disk.png');
        if (is_file($path)) {
            unlink($path);
        }

        $page = PageFactory::createOne([
            'slug' => 'analyzer-missing-file',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/missing-on-disk.png',
                        'alt' => 'Missing',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_file', $findings[0]->status);
        self::assertSame('Sync media files locally', $findings[0]->recommendation);
    }

    public function testDetectsRichtextSnippetWithLegacyLargeSizeClass(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-richtext-snippet',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"><img src="/uploads/media/snippet.png" alt="Snippet"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('richtext', $findings[0]->blockType);
        self::assertSame('lg', $findings[0]->currentSize);
    }

    public function testAnalyzeAllPagesWhenNoPageIdFilter(): void
    {
        self::bootKernel();

        $this->createImagePage('analyzer-all-1');
        $this->createImagePage('analyzer-all-2');

        $findings = $this->analyzer()->analyze();

        self::assertGreaterThanOrEqual(2, count($findings));
    }

    public function testReturnsEmptyFindingsForUnknownPageId(): void
    {
        self::bootKernel();

        self::assertSame([], $this->analyzer()->analyze(999_999));
    }

    public function testDetectsHighlightBlockWithFloatedSnippet(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne(['filename' => 'highlight-float.png'])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-highlight',
            'content' => [
                [
                    'type' => 'highlight',
                    'enabled' => true,
                    'data' => [
                        'html' => sprintf(
                            '<figure class="page-content-image page-content-image--size-md page-content-image--float-right"><img src="/uploads/media/%s" alt="Highlight"></figure>',
                            $media->getFilename(),
                        ),
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('highlight', $findings[0]->blockType);
        self::assertSame('right', $findings[0]->float);
        self::assertSame('md', $findings[0]->currentSize);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testDetectsAccordionItemWithImageSnippet(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-accordion',
            'content' => [
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            [
                                'title' => 'FAQ',
                                'html' => '<figure class="page-content-image page-content-image--size-lg"><img src="/uploads/media/accordion.png" alt="Accordion"></figure>',
                            ],
                        ],
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('accordion item 1', $findings[0]->blockType);
    }

    public function testDetectsInlineImageWithoutFigureClasses(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne(['filename' => 'inline-only.png'])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-inline-img',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => sprintf(
                            '<p>Text <img src="/uploads/media/%s" alt="Inline"></p>',
                            $media->getFilename(),
                        ),
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('inline img', $findings[0]->blockType);
        self::assertSame('auto', $findings[0]->currentSize);
    }

    public function testReportsDimensionsUnknownWhenDatabaseMetadataMissing(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'dims-unknown.png',
            'width' => null,
            'height' => null,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-dims-unknown',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/dims-unknown.png',
                        'alt' => 'Unknown dims',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(1, $findings[0]->imageWidth);
        self::assertSame(1, $findings[0]->imageHeight);
    }

    public function testReportsExternalSrcForNonMediaPath(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-external-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => 'https://example.com/photo.png',
                        'alt' => 'External',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('external_src', $findings[0]->status);
    }

    public function testReportsMissingSrcWhenImageBlockHasNoMediaOrSrc(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-missing-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'alt' => 'No source',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_src', $findings[0]->status);
    }

    public function testResolvesMediaByStringMediaId(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'string-media-id.png',
            'width' => 320,
            'height' => 200,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-string-media-id',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => (string) $media->getId(),
                        'src' => '/uploads/media/string-media-id.png',
                        'alt' => 'String id',
                        'size' => 'sm',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame($media->getId(), $findings[0]->mediaId);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testResolvesLegacyWidthPresetToSize(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'legacy-preset.png',
            'width' => 400,
            'height' => 300,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-legacy-preset',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/legacy-preset.png',
                        'alt' => 'Preset',
                        'widthPreset' => 'md',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('md', $findings[0]->currentSize);
    }

    public function testResolvesSmallAndLargeLegacyWidthPresets(): void
    {
        self::bootKernel();

        $small = MediaFactory::createOne([
            'filename' => 'preset-sm.png',
            'width' => 200,
            'height' => 100,
        ])->_real();
        $large = MediaFactory::createOne([
            'filename' => 'preset-lg.png',
            'width' => 900,
            'height' => 600,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-preset-sm-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $small->getId(),
                        'src' => '/uploads/media/preset-sm.png',
                        'alt' => 'Small preset',
                        'widthPreset' => 'sm',
                        'float' => 'none',
                    ],
                ],
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $large->getId(),
                        'src' => '/uploads/media/preset-lg.png',
                        'alt' => 'Large preset',
                        'widthPreset' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(2, $findings);
        self::assertSame('sm', $findings[0]->currentSize);
        self::assertSame('lg', $findings[1]->currentSize);
        self::assertSame('No change', $findings[0]->recommendation);
        self::assertSame('Review layout', $findings[1]->recommendation);
    }

    public function testLargeFullWidthImageRecommendsReviewLayout(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'wide-image.png',
            'width' => 1200,
            'height' => 800,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-wide-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/wide-image.png',
                        'alt' => 'Wide',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('Review layout', $findings[0]->recommendation);
    }

    public function testUnknownSnippetSizeRecommendsReviewLayout(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne(['filename' => 'xl-snippet.png'])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-unknown-snippet-size',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => sprintf(
                            '<figure class="page-content-image page-content-image--size-xl"><img src="/uploads/media/%s" alt="XL"></figure>',
                            $media->getFilename(),
                        ),
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('xl', $findings[0]->currentSize);
        self::assertSame('Review layout', $findings[0]->recommendation);
    }

    public function testSnippetWithoutSrcReportsMissingSrc(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-snippet-no-src',
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

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_src', $findings[0]->status);
    }

    public function testDetectsFloatedLeftSnippetInHtml(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-float-left-snippet',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-sm page-content-image--float-left"><img src="/uploads/media/float-left.png" alt="Left"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('left', $findings[0]->float);
    }

    public function testResolvesMediaBySrcFilenameWhenMediaIdMissing(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'src-lookup.png',
            'width' => 200,
            'height' => 100,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-src-lookup',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => '/uploads/media/src-lookup.png',
                        'alt' => 'Lookup',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame($media->getId(), $findings[0]->mediaId);
    }

    public function testResolvesSrcOnlyImageWhenNoMediaRecordExists(): void
    {
        self::bootKernel();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $filename = 'orphan-src-only.png';
        $path = Path::join($projectDir, 'public', 'uploads', 'media', $filename);
        if (!is_file($path)) {
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        }

        $page = PageFactory::createOne([
            'slug' => 'analyzer-orphan-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => '/uploads/media/'.$filename,
                        'alt' => 'Orphan',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->mediaId);
        self::assertSame($filename, $findings[0]->filename);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(1, $findings[0]->imageWidth);
    }

    public function testSkipsAccordionItemsWithoutHtml(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-accordion-invalid-item',
            'content' => [
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            ['title' => 'No html'],
                            [
                                'title' => 'With snippet',
                                'html' => '<figure class="page-content-image page-content-image--size-md"><img src="/uploads/media/acc-valid.png" alt="Valid"></figure>',
                            ],
                        ],
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('accordion item 2', $findings[0]->blockType);
    }

    public function testSkipsHtmlBlocksWithEmptyContent(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-empty-html',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => ''],
                ],
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Title only'],
                ],
            ],
        ])->_real();

        self::assertSame([], $this->analyzer()->analyze($page->getId()));
    }

    public function testUnreadableLocalFileReportsDimensionsUnknown(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'unreadable-dims.png',
            'width' => null,
            'height' => null,
        ])->_real();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $path = Path::join($projectDir, 'public', 'uploads', 'media', 'unreadable-dims.png');
        file_put_contents($path, 'not-an-image');

        $page = PageFactory::createOne([
            'slug' => 'analyzer-unreadable-file',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/unreadable-dims.png',
                        'alt' => 'Unreadable',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('dimensions_unknown', $findings[0]->status);
        self::assertSame('Backfill image dimensions', $findings[0]->recommendation);
    }

    private function createImagePage(string $slug): void
    {
        $media = MediaFactory::createOne([
            'filename' => $slug.'.png',
            'width' => 100,
            'height' => 100,
        ])->_real();

        PageFactory::createOne([
            'slug' => $slug,
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/'.$slug.'.png',
                        'alt' => 'Image',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);
    }

    private function analyzer(): PageImageContentAnalyzer
    {
        return self::getContainer()->get(PageImageContentAnalyzer::class);
    }
}
