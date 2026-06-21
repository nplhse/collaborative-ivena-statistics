<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsAnalysisRunner;
use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsCountQuery;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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
            new AllocationsCountQuery($connection, new GenericAnalysisScopeSqlFilter(), $this->createTranslator()),
            new AllocationsCapabilitiesProvider(),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Month,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(new \DateTimeImmutable('2024-01-01 00:00:00')),
        ));

        self::assertSame('allocation_count', $runResult->metricKey->value);
        self::assertSame(20, $runResult->total);
        self::assertCount(2, $runResult->rows);
        self::assertSame('Jun 2024', $runResult->rows[0]->bucketLabel);
        self::assertSame(12, $runResult->rows[0]->value);
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
            new AllocationsCountQuery($connection, new GenericAnalysisScopeSqlFilter(), $this->createTranslator()),
            new AllocationsCapabilitiesProvider(),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Year,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame('2024', $runResult->rows[0]->bucketLabel);
    }

    public function testGenderTotalUsesGenderCodeColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => 1, 'allocation_count' => 3],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'gender_code AS bucket')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Male');

        $runner = new AllocationsAnalysisRunner(
            new AllocationsCountQuery($connection, new GenericAnalysisScopeSqlFilter(), $translator),
            new AllocationsCapabilitiesProvider(),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Total,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame('Male', $runResult->rows[0]->bucketLabel);
    }

    public function testGenderMonthUsesTimeAndSeriesGrouping(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024-06', 'series' => 1, 'allocation_count' => 3],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_month AS bucket')
                && str_contains($sql, 'gender_code AS series')
                && str_contains($sql, 'GROUP BY bucket, series')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Male');

        $runner = new AllocationsAnalysisRunner(
            new AllocationsCountQuery($connection, new GenericAnalysisScopeSqlFilter(), $translator),
            new AllocationsCapabilitiesProvider(),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Month,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertTrue($runResult->hasSeries());
        self::assertSame('Jun 2024', $runResult->rows[0]->bucketLabel);
        self::assertSame('Male', $runResult->rows[0]->seriesLabel);
    }

    public function testChartPresenterBuildsBarLineAndGroupedSpecs(): void
    {
        $presenter = new ExplorerChartPresenter();
        $result = new AnalysisRunResult(
            title: 'Allocations over time',
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Month,
            rows: [
                new AnalysisResultRow(bucket: '2024-06', bucketLabel: 'Jun 2024', seriesKey: null, seriesLabel: null, value: 5),
            ],
            total: 5,
        );

        $barSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Bar));
        self::assertArrayHasKey('bar', $barSpecs);
        self::assertSame('bar', $barSpecs['bar']['chartType']);

        $lineSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Line));
        self::assertArrayHasKey('line', $lineSpecs);
        self::assertSame('line', $lineSpecs['line']['chartType']);

        $seriesResult = new AnalysisRunResult(
            title: 'Allocations by gender over time',
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Month,
            rows: [
                new AnalysisResultRow(bucket: '2024-06', bucketLabel: 'Jun 2024', seriesKey: '1', seriesLabel: 'Male', value: 5),
                new AnalysisResultRow(bucket: '2024-06', bucketLabel: 'Jun 2024', seriesKey: '2', seriesLabel: 'Female', value: 3),
            ],
            total: 8,
        );

        $groupedSpecs = $presenter->buildSpecs($seriesResult, new PresentationConfig(chartType: ChartPresentationType::GroupedBar));
        self::assertArrayHasKey('grouped_bar', $groupedSpecs);
        self::assertTrue($groupedSpecs['grouped_bar']['barGrouped']);
        self::assertCount(2, $groupedSpecs['grouped_bar']['series']);

        $stackedSpecs = $presenter->buildSpecs($seriesResult, new PresentationConfig(chartType: ChartPresentationType::StackedBar));
        self::assertArrayHasKey('stacked_bar', $stackedSpecs);
        self::assertArrayNotHasKey('barGrouped', $stackedSpecs['stacked_bar']);
        self::assertCount(2, $stackedSpecs['stacked_bar']['series']);
    }

    public function testUrgencyYearUsesCreatedYearAndSeriesGrouping(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024', 'series' => 2, 'allocation_count' => 7],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_year AS bucket')
                && str_contains($sql, 'urgency_code AS series')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Inpatient');

        $runner = new AllocationsAnalysisRunner(
            new AllocationsCountQuery($connection, new GenericAnalysisScopeSqlFilter(), $translator),
            new AllocationsCapabilitiesProvider(),
        );

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Urgency,
            timeGrain: AnalysisDimensionGrain::Year,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertTrue($runResult->hasSeries());
        self::assertSame('2024', $runResult->rows[0]->bucketLabel);
        self::assertSame('Inpatient', $runResult->rows[0]->seriesLabel);
    }

    private function createTranslator(): TranslatorInterface
    {
        return $this->createMock(TranslatorInterface::class);
    }
}
