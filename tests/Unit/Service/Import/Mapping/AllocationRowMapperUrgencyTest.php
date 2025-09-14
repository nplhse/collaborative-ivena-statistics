<?php

namespace App\Tests\Unit\Service\Import\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperUrgencyTest extends TestCase
{
    #[DataProvider('pzcProvider')]
    public function testNormalizeUrgencyFromPZC(?string $input, ?int $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeUrgencyFromPZC($input));
    }

    /**
     * @return iterable<string, array{0:?string,1:?int}>
     */
    public static function pzcProvider(): iterable
    {
        yield 'valid: last digit 1' => ['123451', 1];
        yield 'valid: last digit 2' => ['000002', 2];
        yield 'valid: last digit 3' => ['999993', 3];
        yield 'valid: last digit 0' => ['123450', null];
        yield 'invalid: too short' => ['12345', null];
        yield 'invalid: too long' => ['1234567', null];
        yield 'invalid: non-digit' => ['12a456', null];
        yield 'invalid: null' => [null, null];
        yield 'invalid: whitespace trimmed' => [' 123451 ', 1];
    }
}
