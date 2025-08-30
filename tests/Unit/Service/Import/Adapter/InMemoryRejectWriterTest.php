<?php

namespace App\Tests\Unit\Service\Import\Adapter;

use App\Service\Import\Adapter\InMemoryRejectWriter;
use PHPUnit\Framework\TestCase;

final class InMemoryRejectWriterTest extends TestCase
{
    public function testWriteIncrementsCountAndStoresRecords(): void
    {
        // Arrange
        $writer = new InMemoryRejectWriter();

        // Act
        $writer->write(['a' => '1'], ['err one'], 2);
        $writer->write(['b' => '2'], ['err two'], 5);

        // Assert
        self::assertSame(2, $writer->getCount());
        $all = $writer->all();
        self::assertCount(2, $all);
        self::assertSame(2, $all[0]['line']);
        self::assertSame(['err one'], $all[0]['messages']);
        self::assertSame(['a' => '1'], $all[0]['row']);
        self::assertSame(5, $all[1]['line']);
        self::assertSame(['b' => '2'], $all[1]['row']);
        self::assertNull($writer->getPath());
    }

    public function testToCsvRendersHeaderAndRows(): void
    {
        // Arrange
        $writer = new InMemoryRejectWriter();
        $writer->write(['x' => 'foo', 'y' => 'bar'], ['bad', 'wrong'], 10);
        $writer->write(['z' => 'baz'], ['oops'], null);

        // Act
        $csv = $writer->toCsv(';');

        // Assert
        $lines = explode("\n", trim($csv));
        self::assertCount(3, $lines);
        self::assertSame('line;error_messages;row_json', $lines[0]);
        self::assertStringContainsString('10;bad | wrong;{"x":"foo","y":"bar"}', $lines[1]);
        self::assertStringContainsString(';oops;{"z":"baz"}', $lines[2]);
    }
}
