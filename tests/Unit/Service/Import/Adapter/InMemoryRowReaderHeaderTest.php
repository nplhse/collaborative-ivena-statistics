<?php
namespace App\Tests\Unit\Service\Import\Adapter;

use App\Tests\Doubles\Service\Import\Adapter\InMemoryRowReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InMemoryRowReaderHeaderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?array, 1: ?array}>
     */
    public static function rawHeaderProvider(): iterable
    {
        yield 'null header' => [null, null];
        yield 'leer' => [[], []];
        yield 'einfach' => [['A', 'B', 'C'], ['A', 'B', 'C']];
        yield 'mit Umlauten/Spaces' => [['  Ä  ', 'B  ', ' C '], ['  Ä  ', 'B  ', ' C ']];
    }

    /**
     * @param list<string>|null $input
     * @param list<string>|null $expected
     */
    #[DataProvider('rawHeaderProvider')]
    public function test_raw_header_returns_original_values(?array $input, ?array $expected): void
    {
        $reader = new InMemoryRowReader($input, []);
        $raw = $reader->rawHeader();

        // prüft exakte Beibehaltung & Reihenfolge
        self::assertSame($expected, $raw);
    }

    public function test_rows_yield_all_rows_in_order(): void
    {
        /** @var list<list<string>> $numeric */
        $numeric = [
            ['r1c1', 'r1c2'],
            ['r2c1', 'r2c2', 'r2c3'],
            ['r3c1'],
        ];

        $reader = new InMemoryRowReader(null, $numeric);

        /** @var list<list<string>> $rows */
        $rows = \iterator_to_array($reader->rows(), false);

        self::assertSame($numeric, $rows);
        self::assertSame(['r1c1', 'r1c2'], $rows[0]);
        self::assertSame(['r2c1', 'r2c2', 'r2c3'], $rows[1]);
        self::assertSame(['r3c1'], $rows[2]);
    }
}
