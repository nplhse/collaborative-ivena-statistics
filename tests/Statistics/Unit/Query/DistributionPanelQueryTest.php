<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Query;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelFactory;
use App\Statistics\Infrastructure\Query\DistributionPanelQuery;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DistributionPanelQueryTest extends TestCase
{
    public function testFetchDistributionBuildsGroupedSqlAndMapsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');
        $state = new FilterState(['date_range' => ['from' => '2025-01-01', 'to' => '2025-01-31']]);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('GROUP BY urgency_code, hospital_tier_code'),
                    self::stringContains('created_at >= :date_from AND created_at <= :date_to')
                ),
                [
                    'date_from' => '2025-01-01 00:00:00',
                    'date_to' => '2025-01-31 23:59:59',
                ],
                []
            )
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => '2', 'value' => '10'],
                ['dimension_key' => '2', 'group_key' => null, 'value' => '5'],
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $out = $query->fetchDistribution($panel, $state, 'hospital_tier_code');

        self::assertSame([
            ['dimension_key' => 1, 'group_key' => 2, 'value' => 10],
            ['dimension_key' => 2, 'group_key' => null, 'value' => 5],
        ], $out);
    }

    public function testFetchDistributionWithoutGroupingUsesNullGroupKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $sqlFilterBuilder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');
        $state = new FilterState(['date_range' => 'all_cases']);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('NULL AS group_key'), [], [])
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => null, 'value' => '3'],
            ]);

        $query = new DistributionPanelQuery($connection, $sqlFilterBuilder);
        $out = $query->fetchDistribution($panel, $state);

        self::assertSame([['dimension_key' => 1, 'group_key' => null, 'value' => 3]], $out);
    }
}
