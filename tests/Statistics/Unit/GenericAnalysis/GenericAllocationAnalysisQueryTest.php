<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class GenericAllocationAnalysisQueryTest extends TestCase
{
    public function testEmptyHospitalScopeReturnsEmptyResultWithoutQuerying(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeQuery');

        $query = new GenericAllocationAnalysisQuery(
            $connection,
            new GenericAllocationAnalysisSqlBuilder(
                new DimensionRegistry(),
                new GenericAnalysisScopeSqlFilter(),
            ),
        );

        $result = $query->execute(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: new StatisticsScopeCriteria([]),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame([], $result->rows);
        self::assertSame(0, $result->grandTotal);
    }
}
