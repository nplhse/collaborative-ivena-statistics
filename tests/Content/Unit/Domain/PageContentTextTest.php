<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain;

use App\Content\Domain\PageContentText;
use PHPUnit\Framework\TestCase;

final class PageContentTextTest extends TestCase
{
    public function testKeepsTheTextOfEnabledBlocksInOrderWithoutMarkup(): void
    {
        $text = PageContentText::extract([
            ['type' => 'headline', 'data' => ['text' => 'Rejected rows', 'level' => 'h2', 'align' => 'left']],
            ['type' => 'richtext', 'data' => ['html' => '<p>Rows that fail<br>the checks</p><ul><li>missing&nbsp;time</li><li>R&amp;D</li></ul>']],
            ['type' => 'richtext', 'enabled' => false, 'data' => ['html' => '<p>hidden draft text</p>']],
            ['type' => 'image', 'data' => ['mediaId' => 12, 'src' => '/media/x.webp', 'alt' => 'Import report', 'caption' => 'The report', 'size' => 'md']],
            ['type' => 'cta', 'data' => ['headline' => 'Need more?', 'buttonLabel' => 'Contact us', 'buttonUrl' => '/contact']],
            ['type' => 'accordion', 'data' => ['items' => [
                ['title' => 'Export fails', 'html' => '<p>Export by quarter.</p>', 'openByDefault' => true],
            ]]],
            ['type' => 'help_topics', 'data' => []],
        ]);

        self::assertSame(
            'Rejected rows Rows that fail the checks missing time R&D Import report The report Need more? Contact us Export fails Export by quarter.',
            $text,
        );
    }

    public function testEmptyContentHasNoText(): void
    {
        self::assertSame('', PageContentText::extract([]));
    }
}
