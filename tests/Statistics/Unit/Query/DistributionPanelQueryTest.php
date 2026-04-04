<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Query;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetric;
use App\Statistics\Infrastructure\Query\DistributionPanelQuery;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DistributionPanelQueryTest extends TestCase
{
    public function testFetchDistributionBuildsGroupedSqlAndMapsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('GROUP BY urgency_code, hospital_tier_code'),
                    self::stringContains('COUNT(DISTINCT hospital_id)'),
                    self::stringContains('created_at >= :date_from AND created_at <= :date_to')
                ),
                [
                    'date_from' => '2025-01-01 00:00:00',
                    'date_to' => '2025-01-31 23:59:59',
                ],
                []
            )
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => '2', 'value' => '10', 'distinct_hospitals' => '3'],
                ['dimension_key' => '2', 'group_key' => null, 'value' => '5', 'distinct_hospitals' => '2'],
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $out = $query->fetchDistribution($panel, $state, 'hospital_tier_code');

        self::assertSame([
            ['dimension_key' => 1, 'group_key' => 2, 'value' => 10, 'distinct_hospitals' => 3],
            ['dimension_key' => 2, 'group_key' => null, 'value' => 5, 'distinct_hospitals' => 2],
        ], $out);
    }

    public function testFetchDistributionWithoutGroupingUsesNullGroupKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('NULL AS group_key'),
                    self::stringContains('COUNT(DISTINCT hospital_id)')
                ),
                [],
                []
            )
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => null, 'value' => '3', 'distinct_hospitals' => '2'],
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $out = $query->fetchDistribution($panel, $state);

        self::assertSame([['dimension_key' => 1, 'group_key' => null, 'value' => 3, 'distinct_hospitals' => 2]], $out);
    }

    public function testFetchAgeCohortUsesCaseExpression(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::ageCohort();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('WHEN age IS NULL THEN -1'),
                    self::stringContains('GROUP BY (CASE'),
                    self::stringContains('COUNT(DISTINCT hospital_id)')
                ),
                [],
                []
            )
            ->willReturn([]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $query->fetchDistribution($panel, $state);
    }

    public function testFetchNumericDistributionStatsAppendsColumnNotNullAndPercentiles(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('age IS NOT NULL'),
                    self::stringContains('percentile_cont(0.25) WITHIN GROUP (ORDER BY age)'),
                    self::stringContains('GROUP BY urgency_code')
                ),
                [],
                []
            )
            ->willReturn([
                [
                    'dimension_key' => '1',
                    'group_key' => null,
                    'n' => '4',
                    'mean_val' => '40.5',
                    'min_val' => '18',
                    'q1_val' => '30',
                    'median_val' => '40',
                    'q3_val' => '50',
                    'max_val' => '60',
                ],
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $out = $query->fetchNumericDistributionStats($panel, $state, DistributionNumericMetric::Age);

        self::assertSame([
            [
                'dimension_key' => 1,
                'group_key' => null,
                'n' => 4,
                'mean' => 40.5,
                'min' => 18,
                'q1' => 30.0,
                'median' => 40.0,
                'q3' => 50.0,
                'max' => 60,
            ],
        ], $out);
    }

    public function testFetchOverallNumericStatsReturnsNullWhenNoRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'n' => '0',
                'mean_val' => null,
                'min_val' => null,
                'q1_val' => null,
                'median_val' => null,
                'q3_val' => null,
                'max_val' => null,
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        self::assertNull($query->fetchOverallNumericStats($panel, $state, DistributionNumericMetric::Age));
    }

    public function testFetchOverallNumericStatsReturnsParsedRowWhenPresent(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'n' => '12',
                'mean_val' => '41.25',
                'min_val' => '20',
                'q1_val' => '35',
                'median_val' => '40',
                'q3_val' => '48',
                'max_val' => '70',
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        self::assertSame(
            [
                'n' => 12,
                'mean' => 41.25,
                'min' => 20,
                'q1' => 35.0,
                'median' => 40.0,
                'q3' => 48.0,
                'max' => 70,
            ],
            $query->fetchOverallNumericStats($panel, $state, DistributionNumericMetric::Age),
        );
    }

    public function testFetchOverallHospitalParticipationReturnsRatioInputs(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('COUNT(DISTINCT hospital_id)'),
                    self::stringContains('COUNT(*) AS allocations')
                ),
                [],
                []
            )
            ->willReturn(['allocations' => '100', 'distinct_hospitals' => '4']);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        self::assertSame(
            ['allocations' => 100, 'distinct_hospitals' => 4],
            $query->fetchOverallHospitalParticipation($panel, $state),
        );
    }

    public function testFetchOverallHospitalParticipationReturnsNullWhenNoAllocations(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();
        $state = new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['allocations' => '0', 'distinct_hospitals' => '0']);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        self::assertNull($query->fetchOverallHospitalParticipation($panel, $state));
    }
}
