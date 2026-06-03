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

    public function testExecuteMapsRowsAndGrandTotal(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '1', 'value' => '3', 'series' => '2'],
            ['bucket' => '2', 'value' => 7],
        ]);
        $connection->expects(self::once())->method('executeQuery')->willReturn($result);

        $query = new GenericAllocationAnalysisQuery($connection, $this->createSqlBuilder());

        $analysisResult = $query->execute(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'urgency',
        ));

        self::assertSame(10, $analysisResult->grandTotal);
        self::assertCount(2, $analysisResult->rows);
        self::assertSame(1, $analysisResult->rows[0]->bucket);
        self::assertSame(3, $analysisResult->rows[0]->value);
        self::assertSame(2, $analysisResult->rows[0]->series);
        self::assertSame(7, $analysisResult->rows[1]->value);
        self::assertNull($analysisResult->rows[1]->series);
    }

    public function testExecuteNormalizesNumericStringBuckets(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '12.5', 'value' => 1],
        ]);
        $connection->method('executeQuery')->willReturn($result);

        $analysisResult = new GenericAllocationAnalysisQuery($connection, $this->createSqlBuilder())->execute(
            new AnalysisQuery(
                primaryDimensionKey: 'month',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
        );

        self::assertSame(12.5, $analysisResult->rows[0]->bucket);
    }

    private function createSqlBuilder(): GenericAllocationAnalysisSqlBuilder
    {
        return new GenericAllocationAnalysisSqlBuilder(
            new DimensionRegistry(),
            new GenericAnalysisScopeSqlFilter(),
        );
    }
}
