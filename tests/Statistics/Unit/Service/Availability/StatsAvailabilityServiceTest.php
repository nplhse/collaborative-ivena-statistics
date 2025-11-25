<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Availability;

use App\Statistics\Infrastructure\Availability\StatsAvailabilityService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StatsAvailabilityServiceTest extends TestCase
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

    public function testBuildMatrixNonCohortCollectsYearsAndMonths(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(function (string $sql): bool {
                    // Assert SQL references the non-cohort table and the granularity filter
                    // (Don't be overly strict with the exact SQL string.)
                    self::assertStringContainsString('FROM agg_allocations_counts', $sql);
                    self::assertStringContainsString("IN ('year','month','years','months')", $sql);

                    return true;
                }),
                ['t' => 'hospital', 'i' => '123']
            )
            ->willReturn([
                // DB already applies LOWER() in SELECT, but we also test the 'months' normalization
                ['period_gran' => 'year', 'period_key' => '2024-01-01'],
                ['period_gran' => 'months', 'period_key' => new \DateTimeImmutable('2025-11-01')],
            ]);

        $sut = new StatsAvailabilityService($this->db);

        // Act
        $result = $sut->buildMatrix('hospital', '123');

        // Assert
        self::assertSame(range(1, 12), $result['months'], 'months must be 1..12');

        // years must be sorted numerically and cast to int
        self::assertSame([2024, 2025], $result['years']);

        // presence maps
        self::assertArrayHasKey('2024-01-01', $result['hasYear']);
        self::assertTrue($result['hasYear']['2024-01-01']);

        self::assertArrayHasKey('2025-11-01', $result['hasMonth']);
        self::assertTrue($result['hasMonth']['2025-11-01']);
    }

    public function testBuildMatrixCohortUsesBothCohortTablesAndMergesDistinct(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(function (string $sql): bool {
                    // Should UNION the two cohort tables
                    self::assertStringContainsString('FROM agg_allocations_cohort_sums', $sql);
                    self::assertStringContainsString('FROM agg_allocations_cohort_stats', $sql);
                    self::assertStringContainsString('UNION ALL', $sql);

                    return true;
                }),
                ['t' => 'hospital_tier', 'i' => 'advanced']
            )
            ->willReturn([
                ['period_gran' => 'years', 'period_key' => '2023-01-01'], // -> year 2023
                ['period_gran' => 'month', 'period_key' => '2024-02-01'], // month Feb 2024
                ['period_gran' => 'year', 'period_key' => '2023-01-01'], // duplicate year; DISTINCT in SQL anyway
            ]);

        $sut = new StatsAvailabilityService($this->db);

        // Act
        $result = $sut->buildMatrix('hospital_tier', 'advanced');

        // Assert
        self::assertSame([2023, 2024], $result['years']);
        self::assertArrayHasKey('2023-01-01', $result['hasYear']);
        self::assertArrayHasKey('2024-02-01', $result['hasMonth']);
    }

    public function testBuildMatrixHandlesDateTimeInterfacePeriodKey(): void
    {
        // Arrange
        $this->db->method('fetchAllAssociative')->willReturn([
            ['period_gran' => 'year', 'period_key' => new \DateTimeImmutable('2020-01-01')],
            ['period_gran' => 'month', 'period_key' => new \DateTime('2021-03-01')],
        ]);

        $sut = new StatsAvailabilityService($this->db);

        // Act
        $result = $sut->buildMatrix('state', 'BY');

        // Assert
        self::assertSame([2020, 2021], $result['years']);
        self::assertTrue($result['hasYear']['2020-01-01']);
        self::assertTrue($result['hasMonth']['2021-03-01']);
    }

    public function testBuildMatrixInvalidDateStringThrows(): void
    {
        // Arrange
        $this->db->method('fetchAllAssociative')->willReturn([
            ['period_gran' => 'month', 'period_key' => 'definitely-not-a-date'],
        ]);

        $sut = new StatsAvailabilityService($this->db);

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->buildMatrix('public', 'x');
    }

    public function testBuildMatrixEmptyResultYieldsEmptyPresenceMapsAndEmptyYears(): void
    {
        // Arrange
        $this->db->method('fetchAllAssociative')->willReturn([]);

        $sut = new StatsAvailabilityService($this->db);

        // Act
        $result = $sut->buildMatrix('hospital', 'nope');

        // Assert
        self::assertSame([], $result['years']);
        self::assertSame(range(1, 12), $result['months']);
        self::assertSame([], $result['hasYear']);
        self::assertSame([], $result['hasMonth']);
    }
}
