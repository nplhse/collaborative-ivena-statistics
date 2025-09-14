<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperAgeTest extends TestCase
{
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
}
