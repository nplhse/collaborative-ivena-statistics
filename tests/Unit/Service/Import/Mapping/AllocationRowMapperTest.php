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

    public static function genderProvider(): iterable
    {
        yield 'M' => ['M', 'M'];
        yield 'm' => ['m', 'M'];
        yield 'W' => ['W', 'W'];
        yield 'w' => ['w', 'W'];
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

    public static function transportProvider(): iterable
    {
        yield 'Boden upper' => ['BODEN', 'Ground'];
        yield 'Boden mixed' => ['Boden', 'Ground'];
        yield 'boden lower' => ['boden', 'Ground'];

        yield 'Luft upper' => ['LUFT', 'Air'];
        yield 'Luft mixed' => ['Luft', 'Air'];
        yield 'luft lower' => ['luft', 'Air'];

        yield 'NAW -> Boden' => ['NAW', 'Ground'];
        yield 'ITW -> Boden' => ['ITW', 'Ground'];
        yield 'MZF -> Boden' => ['MZF', 'Ground'];
        yield 'RTW -> Boden' => ['RTW', 'Ground'];

        yield 'unknown -> null' => ['HEL', null];
        yield 'empty -> null' => ['', null];
        yield 'null  -> null' => [null, null];
    }

    #[DataProvider('booleanProvider')]
    public function testNormalizeBoolean(?string $input, ?bool $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeBoolean($input));
    }

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

    public static function dateTimeCombineProvider(): iterable
    {
        yield 'ok HH:MM' => ['01.01.2025', '12:34', '01.01.2025 12:34'];
        yield 'trim seconds' => ['01.01.2025', '12:34:56', '01.01.2025 12:34'];
        yield 'missing time' => ['01.01.2025', null, null];
        yield 'missing date' => [null, '12:34', null];
        yield 'both missing' => [null, null, null];
    }

    public function testChooseCreatedAtPrefersErstellungsdatumOnMismatch(): void
    {
        self::assertSame(
            '01.01.2025 10:05',
            TraitHelper::chooseCreatedAt('01.01.2025 10:00', '01.01.2025 10:05')
        );
    }

    public function testChooseCreatedAtUsesCombinedIfErstellungsdatumMissing(): void
    {
        self::assertSame(
            '01.01.2025 10:00',
            TraitHelper::chooseCreatedAt('01.01.2025 10:00', null)
        );
    }

    public function testChooseCreatedAtUsesErstellungsdatumIfCombinedMissing(): void
    {
        self::assertSame(
            '02.02.2025 12:34',
            TraitHelper::chooseCreatedAt(null, '02.02.2025 12:34')
        );
    }

    public function testChooseCreatedAtNormalizesSeconds(): void
    {
        self::assertSame(
            '01.01.2025 10:00',
            TraitHelper::chooseCreatedAt('01.01.2025 10:00', '01.01.2025 10:00:30')
        );
    }

    public function testChooseCreatedAtBothMissing(): void
    {
        self::assertNull(TraitHelper::chooseCreatedAt(null, null));
    }
}
