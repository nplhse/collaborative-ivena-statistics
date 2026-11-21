<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Help;

use App\Content\Application\Help\HelpSearchHit;
use PHPUnit\Framework\TestCase;

final class HelpSearchHitTest extends TestCase
{
    public function testExcerptIsEscapedAndMarksMatches(): void
    {
        $hit = new HelpSearchHit(1, 'Rejected rows', '/help/rejected-rows', 'a <b>bold</b> ⟦rejected⟧ row & more');

        self::assertSame('a &lt;b&gt;bold&lt;/b&gt; <mark>rejected</mark> row &amp; more', $hit->excerptHtml());
    }
}
