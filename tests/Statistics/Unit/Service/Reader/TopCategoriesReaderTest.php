<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Reader;

use App\Statistics\Infrastructure\Reader\TopCategoriesReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TopCategoriesReaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        // Arrange (shared): mock DBAL connection
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testReadReturnsAllFieldsParsedWithStringJsonAndCasts(): void
    {
        // Arrange
        $row = [
            'total' => '7', // string -> must be cast to int
            'computed_at' => '2025-11-08 13:45:00',
            'top_occasion' => json_encode([['id' => '1', 'label' => 'Trauma', 'count' => '3']]),
            'top_assignment' => json_encode([['id' => 2, 'label' => 'Ambulance', 'count' => 2]]),
            'top_infection' => json_encode([['id' => null, 'label' => 'Unknown', 'count' => null]]),
            'top_indication' => json_encode([['id' => '9', 'count' => 1]]), // missing label -> "Unknown"
            'top_speciality' => json_encode([['label' => 'Surgery']]), // missing id/count
            'top_department' => json_encode([['id' => '5', 'label' => 'ER']]),
        ];

        $this->db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static fn ($sql) => is_string($sql)),
                self::equalTo(['t' => 'hospital', 'i' => '123', 'g' => 'day', 'k' => '2025-11-08'])
            )
            ->willReturn($row);

        $sut = new TopCategoriesReader($this->db);

        // Act
        $result = $sut->read('hospital', '123', 'day', '2025-11-08');

        // Assert
        // total
        self::assertSame(7, $result['total'], 'total should be cast to int');

        // computedAt
        self::assertInstanceOf(\DateTimeImmutable::class, $result['computedAt']);
        self::assertSame('2025-11-08 13:45:00', $result['computedAt']->format('Y-m-d H:i:s'));

        // occasion
        self::assertSame([['id' => 1, 'label' => 'Trauma', 'count' => 3]], $result['occasion']['items']);
        // assignment
        self::assertSame([['id' => 2, 'label' => 'Ambulance', 'count' => 2]], $result['assignment']['items']);
        // infection (null id + provided label + null count -> 0)
        self::assertSame([['id' => null, 'label' => 'Unknown', 'count' => 0]], $result['infection']['items']);
        // indication (missing label -> "Unknown")
        self::assertSame([['id' => 9, 'label' => 'Unknown', 'count' => 1]], $result['indication']['items']);
        // speciality (missing id/count -> id null, count 0)
        self::assertSame([['id' => null, 'label' => 'Surgery', 'count' => 0]], $result['speciality']['items']);
        // department (missing count -> 0)
        self::assertSame([['id' => 5, 'label' => 'ER', 'count' => 0]], $result['department']['items']);
    }

    public function testReadAcceptsAlreadyDecodedArraysAndSkipsNonArrayEntries(): void
    {
        // Arrange
        $row = [
            'total' => 0,
            'computed_at' => null, // -> computedAt null
            'top_occasion' => [['id' => '1', 'label' => 'A', 'count' => '2'], 'garbage', 42],
            'top_assignment' => [], // stays empty
            'top_infection' => null, // -> []
            'top_indication' => false, // -> []
            'top_speciality' => '[]', // valid empty JSON string -> []
            'top_department' => '[{"id":null,"label":"X"}]', // valid JSON
        ];

        $this->db->method('fetchAssociative')->willReturn($row);

        $sut = new TopCategoriesReader($this->db);

        // Act
        $result = $sut->read('public', 'x', 'day', '2025-11-08');

        // Assert
        self::assertNull($result['computedAt']);
        self::assertSame([['id' => 1, 'label' => 'A', 'count' => 2]], $result['occasion']['items']);
        self::assertSame([], $result['assignment']['items']);
        self::assertSame([], $result['infection']['items']);
        self::assertSame([], $result['indication']['items']);
        self::assertSame([], $result['speciality']['items']);
        self::assertSame([['id' => null, 'label' => 'X', 'count' => 0]], $result['department']['items']);
    }

    public function testReadReturnsAllEmptyWhenNoRow(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn(false); // no row found
        $sut = new TopCategoriesReader($this->db);

        // Act
        $result = $sut->read('hospital', 'missing', 'day', '2025-11-08');

        // Assert
        self::assertSame(0, $result['total']);
        self::assertNull($result['computedAt']);
        foreach (['occasion', 'assignment', 'infection', 'indication', 'speciality', 'department'] as $k) {
            self::assertSame([], $result[$k]['items'], "$k should be empty list");
        }
    }

    public function testReadEmptyComputedAtStringResultsInNull(): void
    {
        // Arrange
        $row = [
            'total' => 1,
            'computed_at' => '',
            'top_occasion' => '[]',
            'top_assignment' => '[]',
            'top_infection' => '[]',
            'top_indication' => '[]',
            'top_speciality' => '[]',
            'top_department' => '[]',
        ];
        $this->db->method('fetchAssociative')->willReturn($row);
        $sut = new TopCategoriesReader($this->db);

        // Act
        $result = $sut->read('state', 'BY', 'day', '2025-11-08');

        // Assert
        self::assertNull($result['computedAt']);
    }

    public function testReadInvalidComputedAtStringThrows(): void
    {
        // Arrange
        $row = [
            'total' => 1,
            'computed_at' => 'definitely-not-a-date',
        ];
        $this->db->method('fetchAssociative')->willReturn($row);
        $sut = new TopCategoriesReader($this->db);

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->read('state', 'BY', 'day', '2025-11-08');
    }

    public function testReadJsonDecodeOfInvalidStringYieldsEmptyList(): void
    {
        // Arrange
        $row = [
            'total' => 0,
            'computed_at' => null,
            'top_occasion' => '{this is not json}',
        ];
        $this->db->method('fetchAssociative')->willReturn($row);
        $sut = new TopCategoriesReader($this->db);

        // Act
        $result = $sut->read('public', 'x', 'day', '2025-11-08');

        // Assert
        self::assertSame([], $result['occasion']['items']);
    }
}
