<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigValidator;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;

final class AnalysisViewConfigValidatorTest extends TestCase
{
    public function testValidAllocationsConfigPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $validator = new AnalysisViewConfigValidator(new AllocationsCapabilitiesProvider());
        $validator->validate(new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Year,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::AllTime,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Line),
            title: 'Allocations over time',
        ));
    }

    public function testGenderWithMonthAndGroupedBarPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $validator = new AnalysisViewConfigValidator(new AllocationsCapabilitiesProvider());
        $validator->validate(new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::AllTime,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::GroupedBar),
            title: 'Allocations by gender over time',
        ));
    }

    public function testGenderWithMonthRejectsBarChartType(): void
    {
        $validator = new AnalysisViewConfigValidator(new AllocationsCapabilitiesProvider());

        try {
            $validator->validate(new AnalysisViewConfig(
                dataSourceKey: AnalysisDataSourceKey::Allocations,
                metricKey: AnalysisMetricKey::AllocationCount,
                dimensionKey: AnalysisDimensionKey::Gender,
                timeGrain: AnalysisDimensionGrain::Month,
                statisticsFilter: new StatisticsFilter(
                    scope: StatisticsFilterScope::Public,
                    hospitalId: null,
                    cohortType: null,
                    period: StatisticsFilterPeriod::AllTime,
                ),
                presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
                title: 'Allocations by gender over time',
            ));
            self::fail('Expected InvalidExplorerConfigException');
        } catch (InvalidExplorerConfigException $exception) {
            self::assertSame('stats.analysis_explorer.validation.unsupported_chart', $exception->translationKey);
            self::assertSame('bar', $exception->parameters['chart']);
        }
    }

    public function testTimeDimensionRejectsTotalGrain(): void
    {
        $validator = new AnalysisViewConfigValidator(new AllocationsCapabilitiesProvider());

        try {
            $validator->validate(new AnalysisViewConfig(
                dataSourceKey: AnalysisDataSourceKey::Allocations,
                metricKey: AnalysisMetricKey::AllocationCount,
                dimensionKey: AnalysisDimensionKey::Time,
                timeGrain: AnalysisDimensionGrain::Total,
                statisticsFilter: new StatisticsFilter(
                    scope: StatisticsFilterScope::Public,
                    hospitalId: null,
                    cohortType: null,
                    period: StatisticsFilterPeriod::AllTime,
                ),
                presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
                title: 'Allocations over time',
            ));
            self::fail('Expected InvalidExplorerConfigException');
        } catch (InvalidExplorerConfigException $exception) {
            self::assertSame('stats.analysis_explorer.validation.unsupported_grain', $exception->translationKey);
        }
    }
}
