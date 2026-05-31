<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperDispatchAreaTest extends TestCase
{
    #[DataProvider('dispatchAreaProvider')]
    public function testNormalizeDispatchArea(?string $input, ?string $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeDispatchArea($input));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function dispatchAreaProvider(): iterable
    {
        yield 'issue 122 trailing Kreis without dash' => ['Rheingau Taunus Kreis', 'Rheingau Taunus'];
        yield 'unchanged canonical name' => ['Rheingau Taunus', 'Rheingau Taunus'];
        yield 'leading Leitstelle prefix' => ['Leitstelle Nord', 'Nord'];
        yield 'leading Kreis prefix' => ['Kreis Offenbach', 'Offenbach'];
        yield 'parenthetical suffix' => ['Frankfurt (Main)', 'Frankfurt'];
        yield 'trailing dash Kreis suffix' => ['Main-Taunus - Kreis', 'Main-Taunus'];
        yield 'null' => [null, null];
        yield 'empty string' => ['', null];
        yield 'whitespace only' => ['   ', null];
    }
}
