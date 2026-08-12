<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageTableOfContentsBuilder;
use App\Content\Application\Slug\SlugGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class PageTableOfContentsBuilderTest extends TestCase
{
    private PageTableOfContentsBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new PageTableOfContentsBuilder(new SlugGenerator(new AsciiSlugger()));
    }

    public function testDisabledShowTocReturnsEmptyItemsAndOriginalContent(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Intro', 'level' => 'h2'],
            ],
        ];

        $result = $this->builder->build($content, false);

        self::assertTrue($result->isEmpty());
        self::assertSame($content, $result->content);
    }

    public function testNoHeadingsReturnsEmptyItems(): void
    {
        $content = [
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<p>Only paragraphs</p>'],
            ],
            [
                'type' => 'accordion',
                'enabled' => true,
                'data' => [
                    'items' => [
                        ['title' => 'FAQ', 'html' => '<p>Answer</p>', 'openByDefault' => false],
                    ],
                ],
            ],
            [
                'type' => 'cta',
                'enabled' => true,
                'data' => ['headline' => 'Call to action', 'buttonLabel' => 'Go', 'buttonUrl' => '/'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertTrue($result->isEmpty());
        self::assertSame($content, $result->content);
    }

    public function testBuildsHierarchyFromHeadlineAndRichtext(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Chapter One', 'level' => 'h1'],
            ],
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<h2>Section A</h2><p>Text</p><h3>Detail</h3>'],
            ],
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Skipped H4', 'level' => 'h4'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertFalse($result->isEmpty());
        self::assertCount(3, $result->items);
        self::assertSame('Chapter One', $result->items[0]['text']);
        self::assertSame(1, $result->items[0]['level']);
        self::assertSame('chapter-one', $result->items[0]['id']);
        self::assertSame('Section A', $result->items[1]['text']);
        self::assertSame(2, $result->items[1]['level']);
        self::assertSame('section-a', $result->items[1]['id']);
        self::assertSame('Detail', $result->items[2]['text']);
        self::assertSame(3, $result->items[2]['level']);

        self::assertSame('chapter-one', $result->content[0]['data']['id'] ?? null);
        self::assertStringContainsString('id="section-a"', (string) $result->content[1]['data']['html']);
        self::assertStringContainsString('id="detail"', (string) $result->content[1]['data']['html']);
        self::assertArrayNotHasKey('id', $result->content[2]['data']);
    }

    public function testDuplicateHeadingTextsGetUniqueIds(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Overview', 'level' => 'h2'],
            ],
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<h2>Overview</h2>'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(2, $result->items);
        self::assertSame('overview', $result->items[0]['id']);
        self::assertSame('overview-2', $result->items[1]['id']);
    }

    public function testIgnoresDisabledBlocksAndEmptyHeadings(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => false,
                'data' => ['text' => 'Hidden', 'level' => 'h2'],
            ],
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => '   ', 'level' => 'h2'],
            ],
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Visible', 'level' => 'h2'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(1, $result->items);
        self::assertSame('Visible', $result->items[0]['text']);
        self::assertSame('visible', $result->items[0]['id']);
    }

    public function testReusesExistingUniqueRichtextHeadingIds(): void
    {
        $content = [
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<h2 id="custom-anchor">Section</h2><p>Body</p>'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(1, $result->items);
        self::assertSame('custom-anchor', $result->items[0]['id']);
        self::assertSame('Section', $result->items[0]['text']);
        self::assertStringContainsString('id="custom-anchor"', (string) $result->content[0]['data']['html']);
    }

    public function testConflictingExistingIdIsReplacedWithUniqueSlug(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => 'Intro', 'level' => 'h2'],
            ],
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<h2 id="intro">Also Intro</h2>'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(2, $result->items);
        self::assertSame('intro', $result->items[0]['id']);
        self::assertSame('also-intro', $result->items[1]['id']);
        self::assertStringContainsString('id="also-intro"', (string) $result->content[1]['data']['html']);
        self::assertStringNotContainsString('id="intro"', (string) $result->content[1]['data']['html']);
    }

    public function testFallsBackToSectionIdWhenHeadingCannotBeSlugified(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => '!!!', 'level' => 'h2'],
            ],
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => '???', 'level' => 'h3'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(2, $result->items);
        self::assertSame('section', $result->items[0]['id']);
        self::assertSame('section-2', $result->items[1]['id']);
    }

    public function testStripsMarkupFromHeadlineText(): void
    {
        $content = [
            [
                'type' => 'headline',
                'enabled' => true,
                'data' => ['text' => '<em>Safe</em> heading', 'level' => 'h2'],
            ],
        ];

        $result = $this->builder->build($content, true);

        self::assertCount(1, $result->items);
        self::assertSame('Safe heading', $result->items[0]['text']);
        self::assertSame('safe-heading', $result->items[0]['id']);
    }
}
