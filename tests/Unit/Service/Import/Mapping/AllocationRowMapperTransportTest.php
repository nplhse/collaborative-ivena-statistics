<?php

namespace App\Tests\Unit\Service\Import\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperTransportTest extends TestCase
{
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
}
