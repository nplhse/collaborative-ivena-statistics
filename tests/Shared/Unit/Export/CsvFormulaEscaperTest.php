<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Export;

use App\Shared\Application\Export\CsvFormulaEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvFormulaEscaperTest extends TestCase
{
    #[DataProvider('provideDangerousValues')]
    public function testEscapesDangerousPrefixes(string $input, string $expected): void
    {
        self::assertSame($expected, CsvFormulaEscaper::escape($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideDangerousValues(): iterable
    {
        yield 'equals' => ['=CMD|"/C calc"!A0', "'=CMD|\"/C calc\"!A0"];
        yield 'plus' => ['+1+1', "'+1+1"];
        yield 'minus text' => ['-text', "'-text"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\tformula", "'\tformula"];
        yield 'cr' => ["\rformula", "'\rformula"];
    }

    public function testLeavesSafeValuesUnchanged(): void
    {
        self::assertSame('', CsvFormulaEscaper::escape(''));
        self::assertSame('hello', CsvFormulaEscaper::escape('hello'));
        self::assertSame('42', CsvFormulaEscaper::escape('42'));
        self::assertSame('Normal text', CsvFormulaEscaper::escape('Normal text'));
    }
}
