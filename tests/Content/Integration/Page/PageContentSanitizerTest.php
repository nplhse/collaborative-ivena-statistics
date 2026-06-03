<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageContentSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageContentSanitizerTest extends KernelTestCase
{
    public function testSanitizerKeepsTrixStyleMarkupAllowedByConfig(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentSanitizer::class);

        $raw = <<<'HTML'
<p>Absatz</p>
<h2>Überschrift</h2>
<ul><li>Punkt</li></ul>
HTML;

        $out = $sut->sanitize([['type' => 'richtext', 'data' => ['html' => $raw]]]);
        $html = (string) ($out[0]['data']['html'] ?? '');

        self::assertStringContainsString('<p>', $html);
        self::assertStringContainsString('Absatz', $html);
        self::assertStringContainsString('<h2>', $html);
    }

    public function testSanitizerPreservesDivBlocksUsedByTrix(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentSanitizer::class);

        $raw = '<div>Zeile mit <strong>Fett</strong></div>';

        $out = $sut->sanitize([['type' => 'richtext', 'data' => ['html' => $raw]]]);
        $html = (string) ($out[0]['data']['html'] ?? '');

        self::assertStringContainsString('Fett', $html);
        self::assertStringContainsString('<div>', $html);
    }

    public function testSanitizerKeepsImageTagsFromMediaSnippets(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentSanitizer::class);

        $raw = '<p>Text</p><img src="/uploads/media/sample.png" alt="Sample">';

        $out = $sut->sanitize([['type' => 'richtext', 'data' => ['html' => $raw]]]);
        $html = (string) ($out[0]['data']['html'] ?? '');

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('/uploads/media/sample.png', $html);
        self::assertStringContainsString('alt="Sample"', $html);
    }

    public function testSanitizerSanitizesHighlightHtml(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentSanitizer::class);

        $raw = '<p>Notice</p><script>alert(1)</script>';

        $out = $sut->sanitize([['type' => 'highlight', 'data' => ['html' => $raw]]]);
        $html = (string) ($out[0]['data']['html'] ?? '');

        self::assertStringContainsString('Notice', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testSanitizerSanitizesAccordionItemHtml(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentSanitizer::class);

        $raw = '<p>Answer</p><script>alert(1)</script>';

        $out = $sut->sanitize([
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        ['title' => 'Q', 'html' => $raw],
                    ],
                ],
            ],
        ]);

        $html = (string) ($out[0]['data']['items'][0]['html'] ?? '');

        self::assertStringContainsString('Answer', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
