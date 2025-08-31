<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperTest extends TestCase
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

    #[DataProvider('transportProvider')]
    public function testNormalizeTransportType(?string $input, ?string $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeTransportType($input));
    }

    /**
     * @return iterable<array{0: string|null, 1: 'G'|'A'|null}>
     */
    public static function transportProvider(): iterable
    {
        yield 'Boden upper' => ['BODEN', 'G'];
        yield 'Boden mixed' => ['Boden', 'G'];
        yield 'boden lower' => ['boden', 'G'];

        yield 'Luft upper' => ['LUFT', 'A'];
        yield 'Luft mixed' => ['Luft', 'A'];
        yield 'luft lower' => ['luft', 'A'];

        yield 'NAW -> Boden' => ['NAW', 'G'];
        yield 'ITW -> Boden' => ['ITW', 'G'];
        yield 'MZF -> Boden' => ['MZF', 'G'];
        yield 'RTW -> Boden' => ['RTW', 'G'];

        yield 'unknown -> null' => ['HEL', null];
        yield 'empty -> null' => ['', null];
        yield 'null  -> null' => [null, null];
    }

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

    #[DataProvider('ageProvider')]
    public function testNormalizeAge(?string $input, ?int $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeAge($input));
    }

    /**
     * @return iterable<array{0: string|null, 1: int|null}>
     */
    public static function ageProvider(): iterable
    {
        yield 'null -> null' => [null, null];
        yield 'empty -> null' => ['', null];
        yield 'spaces -> int' => [' 23 ', 23];
        yield 'numeric -> int' => ['42', 42];
        yield 'zero -> 0' => ['0', 0];
        yield 'non-numeric' => ['abc', null];
    }

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
