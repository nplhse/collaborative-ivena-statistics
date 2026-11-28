<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageContentSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the `page_richtext` sanitizer configuration (config/packages/framework.yaml) behind the page content; which
 * blocks and fields are sanitized is covered by {@see \App\Tests\Content\Unit\Page\PageContentSanitizerTest}.
 */
final class PageContentSanitizerTest extends KernelTestCase
{
    private PageContentSanitizer $sanitizer;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->sanitizer = self::getContainer()->get(PageContentSanitizer::class);
    }

    public function testKeepsTheMarkupTheEditorAndMediaSnippetsProduce(): void
    {
        $raw = "<p>Absatz</p>\n<h2>Überschrift</h2>\n<ul><li>Punkt</li></ul>"
            .'<div>Zeile mit <strong>Fett</strong></div>'
            .'<p>Start <a href="/help">here</a></p>'
            .'<a href="/uploads/media/sample.png" data-fslightbox="content-gallery"><img src="/uploads/media/sample.png" alt="Sample"></a>';

        $content = $this->sanitizer->sanitize([['type' => 'richtext', 'data' => ['html' => $raw]]]);

        $html = (string) $content[0]['data']['html'];
        self::assertStringContainsString('<p>Absatz</p>', $html);
        self::assertStringContainsString('<h2>', $html);
        self::assertStringContainsString('<li>Punkt</li>', $html);
        self::assertStringContainsString('<div>', $html);
        self::assertStringContainsString('<strong>Fett</strong>', $html);
        self::assertStringContainsString('href="/help"', $html);
        self::assertStringContainsString('data-fslightbox="content-gallery"', $html);
        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="/uploads/media/sample.png"', $html);
        self::assertStringContainsString('alt="Sample"', $html);
    }

    public function testRemovesScripts(): void
    {
        $content = $this->sanitizer->sanitize([['type' => 'richtext', 'data' => ['html' => '<p>Notice</p><script>alert(1)</script>']]]);

        $html = (string) $content[0]['data']['html'];
        self::assertStringContainsString('Notice', $html);
        self::assertStringNotContainsString('script', $html);
    }
}
