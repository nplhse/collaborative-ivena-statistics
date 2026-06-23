<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class AllocationsCapabilitiesProviderTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testCapabilitiesIncludeExpectedDimensionsAndGrains(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();

        self::assertCount(\count(AnalysisDimensionKey::allocationsCatalog()), $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::Time, $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::Gender, $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::Urgency, $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::AgeGroup, $capabilities->dimensions);
        self::assertSame(
            [
                AnalysisDimensionGrain::Month,
                AnalysisDimensionGrain::Year,
                AnalysisDimensionGrain::Quarter,
                AnalysisDimensionGrain::Week,
            ],
            $capabilities->timeGrainsFor(AnalysisDimensionKey::Time),
        );
        self::assertSame(
            [AnalysisDimensionGrain::Total, AnalysisDimensionGrain::Month, AnalysisDimensionGrain::Year],
            $capabilities->timeGrainsFor(AnalysisDimensionKey::Gender),
        );
    }

    public function testSupportsValidTimeConfiguration(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $config = $this->createConfig($capabilities, AnalysisDimensionKey::Time, AnalysisDimensionGrain::Month);

        self::assertTrue($capabilities->supports($config));
    }

    public function testChartTypesForGenderMonthIncludeGroupedBar(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $config = $this->createConfig($capabilities, AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::GroupedBar));

        self::assertContains(ChartPresentationType::GroupedBar, $capabilities->chartTypesFor($config));
        self::assertTrue($capabilities->supports($config));
    }

    public function testChartTypesForGenderTotalExcludeGroupedBar(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $config = $this->createConfig($capabilities, AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Total);

        self::assertSame(
            [ChartPresentationType::Bar, ChartPresentationType::Line],
            $capabilities->chartTypesFor($config),
        );
    }

    private function createConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimension,
        AnalysisDimensionGrain $grain,
    ): \App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig {
        [$rowAxis, $columnAxis] = new \App\Statistics\AnalysisExplorer\Application\AnalysisAxisUpgradeMapper()
            ->fromLegacyDimension($dimension, $grain);

        return new \App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: [$capabilities->defaultMetric],
            visualMetricKey: $capabilities->defaultMetric,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(
                chartType: $capabilities->defaultChartType,
            ),
            title: 'Test',
        );
    }

    public function testScopedCapabilitiesExcludeHospitalOnPublicScope(): void
    {
        $provider = $this->createAllocationsCapabilitiesProvider();
        $unscoped = $provider->capabilities();
        $scoped = $provider->capabilitiesFor(null, new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));

        self::assertContains(AnalysisDimensionKey::Hospital, $unscoped->dimensions);
        self::assertNotContains(AnalysisDimensionKey::Hospital, $scoped->dimensions);
        self::assertNotContains(AnalysisDimensionKey::State, $scoped->dimensions);
        self::assertNotContains(AnalysisDimensionKey::DispatchArea, $scoped->dimensions);
        self::assertContains(AnalysisDimensionKey::Gender, $scoped->dimensions);
    }
}
