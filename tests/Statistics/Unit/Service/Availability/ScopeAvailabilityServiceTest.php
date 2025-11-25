<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Availability;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Availability\ScopeAvailabilityService;
use App\Statistics\Infrastructure\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ScopeAvailabilityServiceTest extends TestCase
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

    public function testGetMatrixNonCohortUsesCountsTableAndGroupsKeys(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(function (string $sql): bool {
                    // Assert some important SQL parts without being brittle
                    self::assertStringContainsString('FROM agg_allocations_counts', $sql);
                    self::assertStringContainsString('GROUP BY period_gran, period_key', $sql);
                    self::assertStringContainsString('ORDER BY period_gran, period_key', $sql);
                    self::assertStringContainsString('period_key::text AS k', $sql);

                    return true;
                }),
                ['t' => 'hospital', 'i' => '123']
            )
            ->willReturn([
                ['period_gran' => Period::YEAR, 'k' => '2024-01-01'],
                ['period_gran' => Period::MONTH, 'k' => '2024-02-01'],
                ['period_gran' => Period::WEEK, 'k' => '2024-W05'],
                ['period_gran' => Period::DAY, 'k' => '2024-02-03'],
                ['period_gran' => Period::QUARTER, 'k' => '2024-Q1'],
                ['period_gran' => Period::ALL, 'k' => 'all'],
                // unknown granularity should be ignored
                ['period_gran' => 'minute', 'k' => '2024-02-03 10:00'],
            ]);

        $sut = new ScopeAvailabilityService($this->db);
        $scope = new Scope('hospital', '123', 'month', '2024-02-01');

        // Act
        $out = $sut->getMatrix($scope);

        // Assert
        // months list is fixed 1..12? -> not part of this service; here we assert per granularity buckets
        self::assertSame(['all'], $out[Period::ALL]);
        self::assertSame(['2024-01-01'], $out[Period::YEAR]);
        self::assertSame(['2024-Q1'], $out[Period::QUARTER]);
        self::assertSame(['2024-02-01'], $out[Period::MONTH]);
        self::assertSame(['2024-W05'], $out[Period::WEEK]);
        self::assertSame(['2024-02-03'], $out[Period::DAY]);
        // unknown granularity should not appear (bucket missing)
        self::assertArrayNotHasKey('minute', $out);
    }

    public function testGetMatrixCohortUsesCohortSumsTable(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(function (string $sql): bool {
                    self::assertStringContainsString('FROM agg_allocations_cohort_sums', $sql);
                    self::assertStringNotContainsString('agg_allocations_counts', $sql);

                    return true;
                }),
                ['t' => 'hospital_tier', 'i' => 'advanced']
            )
            ->willReturn([
                ['period_gran' => Period::YEAR, 'k' => '2023-01-01'],
                ['period_gran' => Period::MONTH, 'k' => '2023-05-01'],
            ]);

        $sut = new ScopeAvailabilityService($this->db);
        $scope = new Scope('hospital_tier', 'advanced', 'year', '2023');

        // Act
        $out = $sut->getMatrix($scope);

        // Assert
        self::assertSame(['2023-01-01'], $out[Period::YEAR]);
        self::assertSame(['2023-05-01'], $out[Period::MONTH]);
    }

    public function testGetMatrixEmptyResultYieldsEmptyBuckets(): void
    {
        // Arrange
        $this->db->method('fetchAllAssociative')->willReturn([]);
        $sut = new ScopeAvailabilityService($this->db);
        $scope = new Scope('public', 'x', 'all', 'all');

        // Act
        $out = $sut->getMatrix($scope);

        // Assert
        self::assertSame([], $out[Period::ALL]);
        self::assertSame([], $out[Period::YEAR]);
        self::assertSame([], $out[Period::QUARTER]);
        self::assertSame([], $out[Period::MONTH]);
        self::assertSame([], $out[Period::WEEK]);
        self::assertSame([], $out[Period::DAY]);
    }

    public function testGetSidebarTreeMergesCountsAndCohortsAndSumsCounts(): void
    {
        // Arrange
        $rowsCounts = [
            // normal rows with 'cnt'
            ['scope_type' => 'hospital', 'scope_id' => '123', 'cnt' => 10],
            ['scope_type' => 'hospital', 'scope_id' => '456', 'cnt' => 1],
            // row with 'count' instead of 'cnt' (service supports both)
            ['scope_type' => 'state', 'scope_id' => 'BY', 'count' => 2],
            // invalid: missing type -> ignored
            ['scope_id' => 'ZZ', 'cnt' => 99],
        ];
        $rowsCohorts = [
            // same key as first -> should sum counts (10 + 3)
            ['scope_type' => 'hospital', 'scope_id' => '123', 'cnt' => 3],
            // new key
            ['scope_type' => 'hospital_tier', 'scope_id' => 'advanced', 'cnt' => 5],
        ];

        $this->db
            ->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use ($rowsCounts, $rowsCohorts) {
                if (str_contains($sql, 'agg_allocations_counts')) {
                    return $rowsCounts;
                }
                if (str_contains($sql, 'agg_allocations_cohort_sums')) {
                    return $rowsCohorts;
                }
                throw new \RuntimeException('Unexpected SQL: '.$sql);
            });

        $sut = new ScopeAvailabilityService($this->db);

        // Act
        $tree = $sut->getSidebarTree();

        // Assert
        // order is not guaranteed; check contents as a set
        $byKey = static fn (array $row) => $row['scope_type'].'|'.$row['scope_id'];
        $indexed = [];
        foreach ($tree as $r) {
            $indexed[$byKey($r)] = $r['count'];
        }

        self::assertSame(13, $indexed['hospital|123']); // 10 + 3
        self::assertSame(1, $indexed['hospital|456']); // from counts only
        self::assertSame(2, $indexed['state|BY']); // 'count' key parsed
        self::assertSame(5, $indexed['hospital_tier|advanced']); // new from cohorts
        self::assertArrayNotHasKey('|ZZ', $indexed, 'row without scope_type must be ignored');
    }
}
