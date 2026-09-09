<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Adapter;

use App\Import\Infrastructure\Adapter\IvenaQuotedCsvParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IvenaQuotedCsvParserTest extends TestCase
{
    private IvenaQuotedCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IvenaQuotedCsvParser();
    }

    public function testUnescapedInnerQuotesRecoverExpectedColumnCount(): void
    {
        $line = '"a";"332 STEMI / "OMI"";"c";"d"';
        $parsed = $this->parser->parseLine($line, ';', '"', '', 4);

        self::assertSame(['a', '332 STEMI / "OMI"', 'c', 'd'], $parsed);
    }

    public function testRfcDoubledQuotesAreALiteralQuote(): void
    {
        $line = '"a";"332 STEMI / ""OMI""";"c"';
        $parsed = $this->parser->parseLine($line, ';', '"', '', 3);

        self::assertSame(['a', '332 STEMI / "OMI"', 'c'], $parsed);
    }

    public function testBackslashQuotedOmiDoesNotShiftColumns(): void
    {
        $line = '"a";"332 STEMI / \\"OMI\\"";"c";"d"';
        $parsed = $this->parser->parseLine($line, ';', '"', '', 4);

        self::assertSame(['a', '332 STEMI / \\"OMI\\"', 'c', 'd'], $parsed);
    }

    public function testTypographicQuotesDoNotNeedLenientFallback(): void
    {
        $line = "\"a\";\"332 STEMI / \u{201C}OMI\u{201D}\";\"c\"";
        $parsed = $this->parser->parseLine($line, ';', '"', '', 3);

        self::assertSame(['a', "332 STEMI / \u{201C}OMI\u{201D}", 'c'], $parsed);
    }

    public function testUnquotedSemicolonLineStillParses(): void
    {
        $parsed = $this->parser->parseLine('foo;bar;baz', ';', '"', '', 3);

        self::assertSame(['foo', 'bar', 'baz'], $parsed);
    }

    public function testMixedUnquotedIvenaRowKeepsColumnCountForBackslashOmiCell(): void
    {
        $line = 'Leitstelle Kassel;1;KH;Nein;332421;"STEMI / \\OMI\\""""";"332 STEMI / \\OMI\\""""";manv';
        $parsed = $this->parser->parseLine($line, ';', '"', '', 8);

        self::assertCount(8, $parsed);
        self::assertSame('Leitstelle Kassel', $parsed[0]);
        self::assertSame('332 STEMI / \\OMI\\""', $parsed[6]);
        self::assertSame('manv', $parsed[7]);
    }

    #[DataProvider('provideEmptyLines')]
    public function testEmptyLineMatchesFgetcsvSentinel(string $line): void
    {
        self::assertSame([null], $this->parser->parseLine($line, ';', '"', ''));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideEmptyLines(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["\n"];
        yield 'crlf' => ["\r\n"];
    }
}
