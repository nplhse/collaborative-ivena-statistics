<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Indication;

use App\Allocation\Domain\IndicationKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndicationKeyTest extends TestCase
{
    public function testOmiQuoteVariantsShareTheSameHash(): void
    {
        $expected = IndicationKey::hashFrom('332', 'STEMI / "OMI"');

        self::assertSame($expected, IndicationKey::hashFrom('332', 'STEMI / \\"OMI\\"'));
        self::assertSame($expected, IndicationKey::hashFrom('332', 'STEMI / \\OMI\\""'));
        self::assertSame($expected, IndicationKey::hashFrom('332', "STEMI / \u{201C}OMI\u{201D}"));
        self::assertSame($expected, IndicationKey::hashFrom('332', "STEMI/\u{201C}OMI\u{201D}"));
        self::assertNotSame($expected, IndicationKey::hashFrom('333', 'STEMI / "OMI"'));
    }

    #[DataProvider('provideQuoteTexts')]
    public function testNormalizeTextCollapsesQuoteStyles(string $left, string $right): void
    {
        self::assertSame(IndicationKey::normalizeText($left), IndicationKey::normalizeText($right));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideQuoteTexts(): iterable
    {
        yield 'backslash vs ascii' => ['STEMI / \\"OMI\\"', 'STEMI / "OMI"'];
        yield 'rfc empty-escape leftover vs ascii' => ['STEMI / \\OMI\\""', 'STEMI / "OMI"'];
        yield 'typographic vs ascii' => ["STEMI / \u{201C}OMI\u{201D}", 'STEMI / "OMI"'];
        yield 'slash spacing' => ['STEMI / "OMI"', 'STEMI/"OMI"'];
        yield 'whitespace' => ['  STEMI   /   "OMI"  ', 'STEMI / "OMI"'];
    }
}
