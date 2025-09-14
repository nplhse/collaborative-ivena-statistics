<?php

namespace App\Tests\Unit\Service\Import\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperGenderTest extends TestCase
{
    #[DataProvider('genderProvider')]
    public function testNormalizeGender(?string $input, string $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeGender($input));
    }

    /**
     * @return iterable<array{0: string|null, 1: 'M'|'F'|'X'}>
     */
    public static function genderProvider(): iterable
    {
        yield 'M' => ['M', 'M'];
        yield 'm' => ['m', 'M'];
        yield 'W' => ['W', 'F'];
        yield 'w' => ['w', 'F'];
        yield 'D->X' => ['D', 'X'];
        yield 'X' => ['X', 'X'];
        yield 'empty' => ['', 'X'];
        yield 'null' => [null, 'X'];
        yield 'other' => ['unknown', 'X'];
    }
}
