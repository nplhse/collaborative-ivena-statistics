<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperDateTest extends TestCase
{
    #[DataProvider('dateTimeCombineProvider')]
    public function testCombineDateAndTime(?string $date, ?string $time, ?string $expected): void
    {
        self::assertSame($expected, TraitHelper::combineDateAndTime($date, $time));
    }

    /**
     * @return iterable<array{0: string|null, 1: string|null, 2: string|null}>
     */
    public static function dateTimeCombineProvider(): iterable
    {
        yield 'ok HH:MM' => ['01.01.2025', '12:34', '01.01.2025 12:34'];
        yield 'four-digit year issue 124 example' => ['16.04.2022', '19:36', '16.04.2022 19:36'];
        yield 'two-digit year normalized to four-digit' => ['16.04.22', '19:36', '16.04.2022 19:36'];
        yield 'empty date string' => ['', '12:34', null];
        yield 'trim seconds' => ['01.01.2025', '12:34:56', '01.01.2025 12:34'];
        yield 'missing time' => ['01.01.2025', null, null];
        yield 'missing date' => [null, '12:34', null];
        yield 'both missing' => [null, null, null];
    }

    #[DataProvider('normalizeImportDatePartProvider')]
    public function testNormalizeImportDatePart(?string $date, ?string $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeImportDatePart($date));
    }

    /**
     * @return iterable<array{0: string|null, 1: string|null}>
     */
    public static function normalizeImportDatePartProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty string' => ['', null];
        yield 'whitespace only' => ['   ', null];
        yield 'non dotted format passthrough' => ['2022-04-16', '2022-04-16'];
        yield 'single digit year segment passthrough' => ['16.04.2', '16.04.2'];
        yield 'three digit year segment passthrough' => ['16.04.202', '16.04.202'];
        yield 'unparseable dotted date passthrough' => ['16.04.xx', '16.04.xx'];
    }
}
