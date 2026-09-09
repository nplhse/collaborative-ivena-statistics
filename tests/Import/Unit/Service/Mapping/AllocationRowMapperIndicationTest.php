<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperIndicationTest extends TestCase
{
    #[DataProvider('indicationProvider')]
    public function testNormalizeIndication(?string $input, ?string $expected): void
    {
        self::assertSame($expected, TraitHelper::normalizeIndication($input));
    }

    /**
     * @return iterable<string, array{0:?string,1:?string}>
     */
    public static function indicationProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'plain label unchanged' => [
            'Gefäßchirurgischer Notfall, sonstiger',
            'Gefäßchirurgischer Notfall, sonstiger',
        ];
        yield '3-digit prefix' => [
            '299 Gefäßchirurgischer Notfall, sonstiger',
            'Gefäßchirurgischer Notfall, sonstiger',
        ];
        yield '6-digit prefix' => [
            '299123 Gefäßchirurgischer Notfall, sonstiger',
            'Gefäßchirurgischer Notfall, sonstiger',
        ];
        yield 'fixture style' => ['123 Test Indication', 'Test Indication'];
        yield 'leading whitespace' => ['  123 Test Indication', 'Test Indication'];
        yield 'gestational age is not a prefix' => [
            '32+0 SSW bis 36+6 SSW und jede Wachstumsstörung',
            '32+0 SSW bis 36+6 SSW und jede Wachstumsstörung',
        ];
        yield 'stemi with quotes' => ['STEMI / "OMI"', 'STEMI / "OMI"'];
        yield 'stemi with 3-digit prefix' => ['332 STEMI / "OMI"', 'STEMI / "OMI"'];
    }
}
