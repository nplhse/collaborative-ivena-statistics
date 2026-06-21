<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsAnalysisRunner;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisDataPoint;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsTimeSeriesCountQuery;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AllocationsAnalysisRunnerTest extends TestCase
{
    public function testRunAggregatesMonthlyAllocationCounts(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024-06', 'allocation_count' => 12],
            ['bucket' => '2024-07', 'allocation_count' => 8],
        ]);
        $connection->method('executeQuery')->willReturn($result);

        $runner = new AllocationsAnalysisRunner(
            new AllocationsTimeSeriesCountQuery($connection, new GenericAnalysisScopeSqlFilter()),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionGrain: AnalysisDimensionGrain::Month,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(new \DateTimeImmutable('2024-01-01 00:00:00')),
        ));

        self::assertSame('allocation_count', $runResult->metricKey->value);
        self::assertSame(20, $runResult->total);
        self::assertCount(2, $runResult->dataPoints);
        self::assertSame('Jun 2024', $runResult->dataPoints[0]->label);
        self::assertSame(12, $runResult->dataPoints[0]->value);
    }

    public function testYearGrainUsesCreatedYearColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024', 'allocation_count' => 5],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_year AS bucket')))
            ->willReturn($result);

        $runner = new AllocationsAnalysisRunner(
            new AllocationsTimeSeriesCountQuery($connection, new GenericAnalysisScopeSqlFilter()),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionGrain: AnalysisDimensionGrain::Year,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame('2024', $runResult->dataPoints[0]->label);
    }

    public function testChartPresenterBuildsBarAndLineSpecs(): void
    {
        $presenter = new ExplorerChartPresenter();
        $result = new AnalysisRunResult(
            title: 'Allocations over time',
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionGrain: AnalysisDimensionGrain::Month,
            dataPoints: [
                new AnalysisDataPoint(bucket: '2024-06', label: 'Jun 2024', value: 5),
            ],
            total: 5,
        );

        $barSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Bar));
        self::assertArrayHasKey('bar', $barSpecs);
        self::assertSame('bar', $barSpecs['bar']['chartType']);

        $lineSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Line));
        self::assertArrayHasKey('line', $lineSpecs);
        self::assertSame('line', $lineSpecs['line']['chartType']);
    }
}
