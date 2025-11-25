<?php

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
        yield 'trim seconds' => ['01.01.2025', '12:34:56', '01.01.2025 12:34'];
        yield 'missing time' => ['01.01.2025', null, null];
        yield 'missing date' => [null, '12:34', null];
        yield 'both missing' => [null, null, null];
    }
}
