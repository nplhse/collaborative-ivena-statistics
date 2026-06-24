<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\BoxPlotTableColumn;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;

final class ExplorerMetricProfileRegistryTest extends TestCase
{
    private ExplorerMetricProfileRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ExplorerMetricProfileRegistry();
    }

    public function testRecognizesDistributionProfiles(): void
    {
        self::assertTrue($this->registry->isProfile(AnalysisMetricKey::BedsDistribution));
        self::assertTrue($this->registry->isProfile(AnalysisMetricKey::AllocationsPerHospitalDistribution));
        self::assertTrue($this->registry->isProfile(AnalysisMetricKey::TransportTimeDistribution));
        self::assertTrue($this->registry->isProfile(AnalysisMetricKey::TransportTimePerHospitalDistribution));
        self::assertFalse($this->registry->isProfile(AnalysisMetricKey::AvgBeds));
    }

    public function testTransportTimeProfilesUseMinutesFormatRegistryKey(): void
    {
        $profile = $this->registry->profileFor(AnalysisMetricKey::TransportTimeDistribution);

        self::assertNotNull($profile);
        self::assertSame('median_transport_time', $profile->formatRegistryKey);
        self::assertSame('median_transport_time', $this->registry->formatRegistryKeyFor(AnalysisMetricKey::TransportTimePerHospitalDistribution));
    }

    public function testProfileDefinesBoxPlotChartAndTableColumns(): void
    {
        $profile = $this->registry->profileFor(AnalysisMetricKey::BedsDistribution);

        self::assertNotNull($profile);
        self::assertSame(ChartPresentationType::BoxPlot, $profile->chartType);
        self::assertSame(BoxPlotTableColumn::cases(), $profile->tableColumns);
        self::assertSame(BoxPlotTableColumn::cases(), $this->registry->tableColumnsFor(AnalysisMetricKey::BedsDistribution));
    }

    public function testProfileAllowsColumnAxisAndCompareButRejectsTemporalRowAxis(): void
    {
        $baseConfig = $this->hospitalConfig();

        self::assertTrue($this->registry->isAllowedForConfig($baseConfig));

        $compareConfig = $baseConfig->withHospitalPopulationMode(ExplorerHospitalPopulationMode::Compare);
        self::assertTrue($this->registry->isAllowedForConfig($compareConfig));

        $matrixConfig = new AnalysisViewConfig(
            dataSourceKey: $baseConfig->dataSourceKey,
            metricKeys: $baseConfig->metricKeys,
            visualMetricKey: $baseConfig->visualMetricKey,
            rowAxis: $baseConfig->rowAxis,
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalLocation),
            statisticsFilter: $baseConfig->statisticsFilter,
            presentation: $baseConfig->presentation,
            title: $baseConfig->title,
            hospitalPopulationMode: $baseConfig->hospitalPopulationMode,
        );
        self::assertTrue($this->registry->isAllowedForConfig($matrixConfig));

        $temporalConfig = new AnalysisViewConfig(
            dataSourceKey: $baseConfig->dataSourceKey,
            metricKeys: $baseConfig->metricKeys,
            visualMetricKey: $baseConfig->visualMetricKey,
            rowAxis: AnalysisAxisRef::time(\App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain::Month),
            columnAxis: null,
            statisticsFilter: $baseConfig->statisticsFilter,
            presentation: $baseConfig->presentation,
            title: $baseConfig->title,
            hospitalPopulationMode: $baseConfig->hospitalPopulationMode,
        );
        self::assertFalse($this->registry->isAllowedForConfig($temporalConfig));
    }

    private function hospitalConfig(): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::BoxPlot),
            title: 'Beds distribution',
        );
    }
}
