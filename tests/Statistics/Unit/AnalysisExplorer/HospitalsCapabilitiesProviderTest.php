<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class HospitalsCapabilitiesProviderTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testComparePopulationModeAllowsGroupedBarWithoutColumnAxis(): void
    {
        $capabilities = $this->createHospitalsCapabilitiesProvider()->capabilitiesFor(null, new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));

        $config = new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::GroupedBar),
            title: 'Hospitals by tier (participation compare)',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Compare,
        );

        self::assertTrue($capabilities->usesMultiSeriesChart($config));
        self::assertContains(ChartPresentationType::GroupedBar, $capabilities->chartTypesFor($config));
        self::assertTrue($capabilities->supports($config));
    }
}
