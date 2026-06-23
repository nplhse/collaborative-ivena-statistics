<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AllocationsAnalysisRunnerTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testRunAggregatesMonthlyAllocationCounts(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024-06', 'count' => 12],
            ['bucket' => '2024-07', 'count' => 8],
        ]);
        $connection->method('executeQuery')->willReturn($result);

        $runner = $this->createAllocationsAnalysisRunner($connection, $this->createTranslator());

        $runResult = $runner->run($this->analysisQuery(
            AnalysisDimensionKey::Time,
            AnalysisDimensionGrain::Month,
        ));

        self::assertSame('allocation_count', $runResult->visualMetricKey->value);
        self::assertSame(20.0, $runResult->totalFor(AnalysisMetricKey::AllocationCount));
        self::assertCount(2, $runResult->rows);
        self::assertSame('2024-06', $runResult->rows[0]->bucketLabel);
        self::assertSame(12, $runResult->rows[0]->valueFor(AnalysisMetricKey::AllocationCount));
    }

    public function testYearGrainUsesCreatedYearColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024', 'count' => 5],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_year AS bucket')))
            ->willReturn($result);

        $runner = $this->createAllocationsAnalysisRunner($connection, $this->createTranslator());

        $runResult = $runner->run($this->analysisQuery(
            AnalysisDimensionKey::Time,
            AnalysisDimensionGrain::Year,
        ));

        self::assertSame('2024', $runResult->rows[0]->bucketLabel);
    }

    public function testGenderTotalUsesGenderCodeColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => 1, 'count' => 3],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'gender_code AS bucket')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Male');

        $runner = $this->createAllocationsAnalysisRunner($connection, $translator);

        $runResult = $runner->run($this->analysisQuery(
            AnalysisDimensionKey::Gender,
            AnalysisDimensionGrain::Total,
        ));

        self::assertSame('Male', $runResult->rows[0]->bucketLabel);
    }

    public function testTimeRowsWithGenderColumnsUseBothAxes(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => '2024-06', 'series' => 1, 'count' => 3],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_month AS bucket')
                && str_contains($sql, 'gender_code AS series')
                && str_contains($sql, 'GROUP BY bucket, series')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Male');

        $runner = $this->createAllocationsAnalysisRunner($connection, $translator);

        [$rowAxis, $columnAxis] = $this->axesFromLegacy(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month);

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertTrue($runResult->hasColumnAxis());
        self::assertSame('2024-06', $runResult->rows[0]->bucketLabel);
        self::assertSame('Male', $runResult->rows[0]->seriesLabel);
    }

    public function testChartPresenterBuildsBarLineAndGroupedSpecs(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $result = new AnalysisRunResult(
            title: 'Allocations over time',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            rows: [
                new AnalysisResultRow(
                    bucket: '2024-06',
                    bucketLabel: 'Jun 2024',
                    seriesKey: null,
                    seriesLabel: null,
                    metricValues: ['allocation_count' => 5],
                ),
            ],
            totals: new AnalysisTotals(grand: ['allocation_count' => 5]),
        );

        $barSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Bar));
        self::assertArrayHasKey('bar', $barSpecs);
        self::assertSame('bar', $barSpecs['bar']['chartType']);
        self::assertSame('Month', $barSpecs['bar']['xAxisLabel']);
        self::assertSame('Count', $barSpecs['bar']['yAxisLabel']);

        $lineSpecs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Line));
        self::assertArrayHasKey('line', $lineSpecs);
        self::assertSame('line', $lineSpecs['line']['chartType']);

        $seriesResult = new AnalysisRunResult(
            title: 'Allocations by gender over time',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            rows: [
                new AnalysisResultRow(
                    bucket: '2024-06',
                    bucketLabel: 'Jun 2024',
                    seriesKey: '1',
                    seriesLabel: 'Male',
                    metricValues: ['allocation_count' => 5],
                ),
                new AnalysisResultRow(
                    bucket: '2024-06',
                    bucketLabel: 'Jun 2024',
                    seriesKey: '2',
                    seriesLabel: 'Female',
                    metricValues: ['allocation_count' => 3],
                ),
            ],
            totals: new AnalysisTotals(grand: ['allocation_count' => 8]),
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
            ['bucket' => '2024', 'series' => 2, 'count' => 7],
        ]);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'created_year AS bucket')
                && str_contains($sql, 'urgency_code AS series')))
            ->willReturn($result);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Inpatient');

        $runner = $this->createAllocationsAnalysisRunner($connection, $translator);

        [$rowAxis, $columnAxis] = $this->axesFromLegacy(AnalysisDimensionKey::Urgency, AnalysisDimensionGrain::Year);

        $runResult = $runner->run(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertTrue($runResult->hasColumnAxis());
        self::assertSame('2024', $runResult->rows[0]->bucketLabel);
        self::assertSame('Inpatient', $runResult->rows[0]->seriesLabel);
    }

    private function analysisQuery(
        AnalysisDimensionKey $dimension,
        AnalysisDimensionGrain $grain,
    ): AnalysisQuery {
        [$rowAxis, $columnAxis] = $this->axesFromLegacy($dimension, $grain);

        return new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(new \DateTimeImmutable('2024-01-01 00:00:00')),
        );
    }

    private function createTranslator(): TranslatorInterface
    {
        return $this->createMock(TranslatorInterface::class);
    }
}
