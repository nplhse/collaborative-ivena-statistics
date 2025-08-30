<?php

namespace App\Tests\Unit\Service\Import\Adapter;

use App\Service\Import\Adapter\InMemoryRowReader;
use PHPUnit\Framework\TestCase;

final class InMemoryRowReaderTest extends TestCase
{
    public function testFromAssocRowsInfersHeaderAndReturnsRowsAssoc(): void
    {
        // Arrange
        $reader = InMemoryRowReader::fromAssocRows([
            ['geschlecht' => 'W', 'alter' => '74'],
            ['geschlecht' => 'M', 'alter' => '65'],
        ]);

        // Act
        $header = $reader->header();
        $rows = \iterator_to_array($reader->rowsAssoc(), false);

        // Assert
        self::assertSame(['geschlecht', 'alter'], $header);
        self::assertCount(2, $rows);
        self::assertSame(['geschlecht' => 'W', 'alter' => '74'], $rows[0]);
        self::assertSame(['geschlecht' => 'M', 'alter' => '65'], $rows[1]);
    }

    public function testConstructorWithHeaderAndNumericRowsBuildsAssocRows(): void
    {
        // Arrange
        $reader = new InMemoryRowReader(
            ['a', 'b', 'c'],
            [
                ['1', '2'],       // fehlendes c -> wird mit '' aufgefüllt
                ['3', '4', '5'],
            ]
        );

        // Act
        $rows = \iterator_to_array($reader->rowsAssoc(), false);

        // Assert
        self::assertSame(['a' => '1', 'b' => '2', 'c' => ''], $rows[0]);
        self::assertSame(['a' => '3', 'b' => '4', 'c' => '5'], $rows[1]);
    }

    public function testRowsAssocWithoutHeaderThrows(): void
    {
        // Arrange
        $reader = new InMemoryRowReader(null, [['1', '2']]);

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        \iterator_to_array($reader->rowsAssoc(), false);
    }
}
