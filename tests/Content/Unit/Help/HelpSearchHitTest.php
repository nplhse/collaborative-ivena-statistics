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

        self::assertSame('a &lt;b&gt;bold&lt;/b&gt; <mark>rejected</mark> row &amp; more', $hit->passages()[0]['html']);
    }

    public function testOnlyAnExcerptWithMarksHasAMatch(): void
    {
        self::assertTrue(new HelpSearchHit(1, 'Rows', '/help/rows', 'some ⟦rows⟧ here')->excerptHasMatch());
        self::assertFalse(new HelpSearchHit(1, 'Rows', '/help/rows', 'start of the text')->excerptHasMatch());
    }

    public function testPassagesShowWhereTheyAreCutFromTheText(): void
    {
        $text = "First words here.  Middle part with rows in it. More text.\nThe last rows end here.";
        $hit = new HelpSearchHit(1, 'Rows', '/help/rows', 'First words here⟬ ⟬part with ⟦rows⟧ in⟬The last ⟦rows⟧ end here', null, $text);

        self::assertSame([
            ['html' => 'First words here', 'cutBefore' => false, 'cutAfter' => true],
            ['html' => 'part with <mark>rows</mark> in', 'cutBefore' => true, 'cutAfter' => true],
            ['html' => 'The last <mark>rows</mark> end here', 'cutBefore' => true, 'cutAfter' => false],
        ], $hit->passages(), 'empty passages dropped; the final full stop does not count as cut');
    }

    public function testThePassageOfAShortTextIsNotCut(): void
    {
        $hit = new HelpSearchHit(1, 'Rows', '/help/rows', '⟦Rows⟧ that fail are rejected', null, 'Rows that fail are rejected.');

        self::assertSame([['html' => '<mark>Rows</mark> that fail are rejected', 'cutBefore' => false, 'cutAfter' => false]], $hit->passages());
    }

    public function testWithoutTheTextNoCutIsShown(): void
    {
        $passage = new HelpSearchHit(1, 'Rows', '/help/rows', 'middle ⟦rows⟧ part')->passages()[0];

        self::assertFalse($passage['cutBefore']);
        self::assertFalse($passage['cutAfter']);
    }
}
