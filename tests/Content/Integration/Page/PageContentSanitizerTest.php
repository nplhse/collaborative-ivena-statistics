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
}
