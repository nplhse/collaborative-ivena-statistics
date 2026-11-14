<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Plausibility;

use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PzcCodeList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PzcCodeListTest extends TestCase
{
    public function testParsesCodesAndRangesUniqueAndSorted(): void
    {
        self::assertSame([263, 711, 712, 713], PzcCodeList::parse("711-713, 263;\n712 "));
        self::assertSame([25, 711, 712], PzcCodeList::parse('711–712 025'), 'en dash and a leading zero');
        self::assertSame([], PzcCodeList::parse('  '));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'four digits' => ['711, 7111', '7111'];
        yield 'text' => ['711 Urologie', 'Urologie'];
        yield 'descending range' => ['718-711', '718-711'];
    }

    #[DataProvider('invalidInput')]
    public function testRefusesTheFirstInvalidToken(string $input, string $token): void
    {
        try {
            PzcCodeList::parse($input);
            self::fail('expected a refusal');
        } catch (PlausibilityException $exception) {
            self::assertSame(PlausibilityException::PZC_INVALID, $exception->reason);
            self::assertSame(['pzc' => $token], $exception->parameters());
        }
    }

    public function testFormatsThreeDigits(): void
    {
        self::assertSame('025, 263, 711', PzcCodeList::format([711, 25, 263]));
    }
}
