<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules;

use App\Tests\PHPStan\Rules\RelativeDateString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelativeDateStringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideStrings(): iterable
    {
        yield 'empty' => ['', true];
        yield 'now' => ['now', true];
        yield 'today' => ['today', true];
        yield 'midnight' => ['midnight', true];
        yield 'time only' => ['10:00', true];
        yield 'weekday' => ['Monday', true];
        yield 'offset' => ['-30 days', true];
        yield 'first day of this month' => ['first day of this month 00:00:00', true];
        yield 'date' => ['2026-05-01', false];
        yield 'date and time' => ['2026-05-01 00:00:00', false];
        yield 'date with offset' => ['2026-05-01 +1 day', false];
        yield 'year and month' => ['2026-05', false];
        yield 'timestamp' => ['@1700000000', false];
        yield 'unparsable' => ['not a date!!', false];
    }

    #[DataProvider('provideStrings')]
    public function testIsRelative(string $value, bool $expected): void
    {
        self::assertSame($expected, RelativeDateString::isRelative($value));
    }
}
