<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisAxisResolver;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisFilterPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerTableLayoutResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisViewConfigNormalizerTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testNormalizesTimeRowsWithGenderColumnsBarToGroupedBar(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Title');

        $normalizer = new AnalysisViewConfigNormalizer(
            $this->createDataSourceCapabilitiesRegistry(),
            new ExplorerTitleFactory($translator),
            new AnalysisAxisResolver(),
            new ExplorerConfigPreviewFactory(),
            $this->createExplorerMetricCapabilityPolicy(),
            new ExplorerTableLayoutResolver(),
            new ExplorerAnalysisFilterPolicy(),
            $this->createSecurityWithoutUser(),
        );

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Old title',
        );

        $normalized = $normalizer->normalize($config);

        self::assertSame(AnalysisDimensionKey::Time, $normalized->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionKey::Gender, $normalized->columnAxis?->dimensionKey);
        self::assertSame(ChartPresentationType::GroupedBar, $normalized->presentation->chartType);
    }

    public function testNormalizesGenderRowsWithoutGrainToTotal(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Title');

        $normalizer = new AnalysisViewConfigNormalizer(
            $this->createDataSourceCapabilitiesRegistry(),
            new ExplorerTitleFactory($translator),
            new AnalysisAxisResolver(),
            new ExplorerConfigPreviewFactory(),
            $this->createExplorerMetricCapabilityPolicy(),
            new ExplorerTableLayoutResolver(),
            new ExplorerAnalysisFilterPolicy(),
            $this->createSecurityWithoutUser(),
        );

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: new AnalysisAxisRef(AnalysisDimensionKey::Gender, null),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Old title',
        );

        $normalized = $normalizer->normalize($config);

        self::assertSame(AnalysisDimensionGrain::Total, $normalized->rowAxis->resolvedGrain());
    }

    public function testForcesBoxPlotForDistributionProfile(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Title');

        $normalizer = new AnalysisViewConfigNormalizer(
            $this->createDataSourceCapabilitiesRegistry(),
            new ExplorerTitleFactory($translator),
            new AnalysisAxisResolver(),
            new ExplorerConfigPreviewFactory(),
            $this->createExplorerMetricCapabilityPolicy(),
            new ExplorerTableLayoutResolver(),
            new ExplorerAnalysisFilterPolicy(),
            $this->createSecurityWithoutUser(),
        );

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution, AnalysisMetricKey::AvgBeds],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalLocation),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Beds distribution',
        );

        $normalized = $normalizer->normalize($config);

        self::assertSame(ChartPresentationType::BoxPlot, $normalized->presentation->chartType);
        self::assertSame([AnalysisMetricKey::BedsDistribution], $normalized->metricKeys);
        self::assertSame(AnalysisDimensionKey::HospitalLocation, $normalized->columnAxis?->dimensionKey);
        self::assertSame(\App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Flat, $normalized->presentation->tableLayout);
    }
}
