<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;

final class PageImageContentAnalyzerHtmlSnippetTest extends PageImageContentAnalyzerTestCase
{
    public function testDetectsRichtextSnippetWithLegacyLargeSizeClass(): void
    {
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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('richtext', $findings[0]->blockType);
        self::assertSame('lg', $findings[0]->currentSize);
    }

    public function testDetectsHighlightBlockWithFloatedSnippet(): void
    {
        $media = MediaFactory::createOne(['filename' => 'highlight-float.png']);

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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('highlight', $findings[0]->blockType);
        self::assertSame('right', $findings[0]->float);
        self::assertSame('md', $findings[0]->currentSize);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testDetectsAccordionItemWithImageSnippet(): void
    {
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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('accordion item 1', $findings[0]->blockType);
    }

    public function testDetectsInlineImageWithoutFigureClasses(): void
    {
        $media = MediaFactory::createOne(['filename' => 'inline-only.png']);

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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('inline img', $findings[0]->blockType);
        self::assertSame('auto', $findings[0]->currentSize);
    }

    public function testUnknownSnippetSizeRecommendsReviewLayout(): void
    {
        $media = MediaFactory::createOne(['filename' => 'xl-snippet.png']);

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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('xl', $findings[0]->currentSize);
        self::assertSame('Review layout', $findings[0]->recommendation);
    }

    public function testSnippetWithoutSrcReportsMissingSrc(): void
    {
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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_src', $findings[0]->status);
    }

    public function testDetectsFloatedLeftSnippetInHtml(): void
    {
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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('left', $findings[0]->float);
    }

    public function testSkipsAccordionItemsWithoutHtml(): void
    {
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
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('accordion item 2', $findings[0]->blockType);
    }

    public function testSkipsHtmlBlocksWithEmptyContent(): void
    {
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
        ]);

        self::assertSame([], $this->analyzer()->analyze($page->getId()));
    }
}
