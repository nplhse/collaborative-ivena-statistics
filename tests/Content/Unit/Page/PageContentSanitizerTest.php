<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Which blocks and fields reach the HTML sanitizer. The stub marks every sanitized value with "clean:", so the
 * expected blocks show which fields went through it; what the configured sanitizer keeps is covered by
 * {@see \App\Tests\Content\Integration\Page\PageContentSanitizerTest}.
 */
final class PageContentSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function htmlBlockTypes(): iterable
    {
        yield 'richtext' => ['richtext'];
        yield 'highlight' => ['highlight'];
    }

    #[DataProvider('htmlBlockTypes')]
    public function testSanitizesTheHtmlOfHtmlBlocks(string $type): void
    {
        $block = ['type' => $type, 'data' => ['html' => '<p>Text</p>', 'style' => 'info'], 'enabled' => true];

        $sanitized = $this->sanitizer()->sanitize([$block]);

        self::assertSame([['type' => $type, 'data' => ['html' => 'clean:<p>Text</p>', 'style' => 'info'], 'enabled' => true]], $sanitized);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function itemBlockTypes(): iterable
    {
        yield 'accordion' => ['accordion'];
        yield 'cards' => ['cards'];
    }

    #[DataProvider('itemBlockTypes')]
    public function testSanitizesTheHtmlOfEveryItemAndKeepsItsOtherFields(string $type): void
    {
        $block = ['type' => $type, 'data' => ['items' => [
            ['title' => 'First', 'html' => '<p>One</p>'],
            ['title' => 'Second', 'html' => '<p>Two</p>'],
        ]]];

        $sanitized = $this->sanitizer()->sanitize([$block]);

        self::assertSame([['type' => $type, 'data' => ['items' => [
            ['title' => 'First', 'html' => 'clean:<p>One</p>'],
            ['title' => 'Second', 'html' => 'clean:<p>Two</p>'],
        ]]]], $sanitized);
    }

    public function testSkipsItemsThatAreNoArray(): void
    {
        $block = ['type' => 'accordion', 'data' => ['items' => [
            'invalid-item',
            ['title' => 'Q', 'html' => '<p>Answer</p>'],
        ]]];

        $sanitized = $this->sanitizer()->sanitize([$block]);

        self::assertSame([['type' => 'accordion', 'data' => ['items' => [
            ['title' => 'Q', 'html' => 'clean:<p>Answer</p>'],
        ]]]], $sanitized);
    }

    public function testPassesNonHtmlBlocksThroughUnchanged(): void
    {
        $block = ['type' => 'headline', 'data' => ['text' => 'Title', 'level' => 'h2']];

        $sanitized = $this->sanitizer()->sanitize([$block]);

        self::assertSame([$block], $sanitized);
    }

    private function sanitizer(): PageContentSanitizer
    {
        $html = $this->createStub(HtmlSanitizerInterface::class);
        $html->method('sanitize')->willReturnCallback(static fn (string $input): string => 'clean:'.$input);

        return new PageContentSanitizer($html);
    }
}
