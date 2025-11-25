<?php

namespace App\Tests\Import\Unit\Service\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperBooleanTest extends TestCase
{
    #[DataProvider('booleanProvider')]
    public function testNormalizeBoolean(?string $input, ?bool $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeBoolean($input));
    }

    /**
     * @return iterable<array{0: string|null, 1: bool|null}>
     */
    public static function booleanProvider(): iterable
    {
        yield 'suffix plus' => ['S+', true];
        yield 'suffix minus' => ['S-', false];
        yield 'German yes' => ['ja', true];
        yield 'German no' => ['nein', false];
        yield '1' => ['1', true];
        yield '0' => ['0', false];
        yield 'x' => ['x', true];
        yield 'unknown -> null' => ['maybe', null];
        yield 'empty -> null' => ['', null];
        yield 'null  -> null' => [null, null];
    }
}
