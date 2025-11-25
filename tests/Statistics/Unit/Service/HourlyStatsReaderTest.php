<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Reader\HourlyStatsReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HourlyStatsReaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testFetchHourlyParsesJsonStringAndBuildsSeriesWith24Values(): void
    {
        // Arrange
        $payload = json_encode([
            'admissions' => array_fill(0, 24, '1'), // strings -> int cast
            'discharges' => range(0, 23), // ints
            'broken' => [1, 2, 3], // ignored (length != 24)
            'notArray' => 123, // ignored (not array)
        ], JSON_THROW_ON_ERROR);

        $row = [
            'hours_count' => $payload,
            'computed_at' => '2025-11-08 10:00:00',
        ];

        $this->db->method('fetchAssociative')->willReturn($row);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('hospital', '123', 'day', '2025-11-08');

        // Act
        $result = $sut->fetchHourly($scope);

        // Assert
        // labels 0..23
        self::assertSame(range(0, 23), $result['labels']);

        // series
        self::assertArrayHasKey('admissions', $result['series']);
        self::assertArrayHasKey('discharges', $result['series']);
        self::assertCount(24, $result['series']['admissions']);
        self::assertCount(24, $result['series']['discharges']);
        self::assertSame(1, $result['series']['admissions'][0]); // casted to int
        self::assertSame(0, $result['series']['discharges'][0]);

        // computedAt
        self::assertInstanceOf(\DateTimeImmutable::class, $result['computedAt']);
        self::assertSame('2025-11-08 10:00:00', $result['computedAt']->format('Y-m-d H:i:s'));
    }

    public function testFetchHourlyAcceptsAlreadyDecodedArray(): void
    {
        // Arrange
        $row = [
            'hours_count' => [
                'metricA' => array_fill(0, 24, 2),
                'metricB' => array_merge(range(0, 22), [999]), // 24 total
            ],
            'computed_at' => null,
        ];
        $this->db->method('fetchAssociative')->willReturn($row);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('public', 'x', 'day', '2025-11-08');

        // Act
        $result = $sut->fetchHourly($scope);

        // Assert
        self::assertSame(range(0, 23), $result['labels']);
        self::assertSame(array_fill(0, 24, 2), $result['series']['metricA']);
        self::assertSame(999, $result['series']['metricB'][23]);
        self::assertNull($result['computedAt']);
    }

    public function testFetchHourlyIgnoresSeriesWithWrongLengthOrWrongShape(): void
    {
        // Arrange
        $row = [
            'hours_count' => json_encode([
                'tooShort' => [1, 2, 3],
                'assoc' => ['00' => 1] + array_fill(1, 23, 0), // array_values will fix keys but len is 24? no -> build full 24 to include
                'valid' => array_fill(0, 24, 5),
            ], JSON_THROW_ON_ERROR),
            'computed_at' => '',
        ];

        // Fix 'assoc' to 24 length with mixed keys
        $row['hours_count'] = json_encode([
            'tooShort' => [1, 2, 3],
            'assoc' => ['0' => 1] + array_fill(1, 23, 0), // 24 entries total; array_values(...) will normalize
            'valid' => array_fill(0, 24, 5),
        ], JSON_THROW_ON_ERROR);

        $this->db->method('fetchAssociative')->willReturn($row);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('state', 'BY', 'day', '2025-11-08');

        // Act
        $result = $sut->fetchHourly($scope);

        // Assert
        self::assertArrayNotHasKey('tooShort', $result['series']); // ignored
        self::assertArrayHasKey('assoc', $result['series']); // accepted (len 24)
        self::assertArrayHasKey('valid', $result['series']); // accepted
        self::assertSame(1, $result['series']['assoc'][0]);
        self::assertSame(5, $result['series']['valid'][0]);
        self::assertNull($result['computedAt']); // empty string -> null
    }

    public function testFetchHourlyNoRowReturnsEmptySeriesAndNullComputedAt(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn(false);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('hospital', 'missing', 'day', '2025-11-08');

        // Act
        $result = $sut->fetchHourly($scope);

        // Assert
        self::assertSame(range(0, 23), $result['labels']);
        self::assertSame([], $result['series']);
        self::assertNull($result['computedAt']);
    }

    public function testFetchHourlyInvalidComputedAtStringThrows(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn([
            'hours_count' => json_encode(['a' => array_fill(0, 24, 0)], JSON_THROW_ON_ERROR),
            'computed_at' => 'definitely-not-a-date',
        ]);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('public', 'x', 'day', '2025-11-08');

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->fetchHourly($scope);
    }

    public function testFetchHourlyInvalidJsonThrowsJsonException(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn([
            'hours_count' => '{not json}', // JSON_THROW_ON_ERROR should bubble up
            'computed_at' => null,
        ]);

        $sut = new HourlyStatsReader($this->db);
        $scope = new Scope('public', 'x', 'day', '2025-11-08');

        // Act & Assert
        $this->expectException(\JsonException::class);
        $sut->fetchHourly($scope);
    }
}
