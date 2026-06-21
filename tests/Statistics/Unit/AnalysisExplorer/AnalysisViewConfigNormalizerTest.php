<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionGrainResolver;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisViewConfigNormalizerTest extends TestCase
{
    public function testNormalizesGenderBarToGroupedBarForMonthGrain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Title');

        $capabilitiesProvider = new AllocationsCapabilitiesProvider();
        $normalizer = new AnalysisViewConfigNormalizer(
            $capabilitiesProvider,
            new ExplorerTitleFactory($translator),
            new AnalysisDimensionGrainResolver(),
            new ExplorerConfigPreviewFactory(),
        );

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Month,
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

        self::assertSame(AnalysisDimensionKey::Gender, $normalized->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $normalized->timeGrain);
        self::assertSame(ChartPresentationType::GroupedBar, $normalized->presentation->chartType);
    }

    public function testNormalizesLegacyNullGenderGrainToTotal(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Title');

        $capabilitiesProvider = new AllocationsCapabilitiesProvider();
        $normalizer = new AnalysisViewConfigNormalizer(
            $capabilitiesProvider,
            new ExplorerTitleFactory($translator),
            new AnalysisDimensionGrainResolver(),
            new ExplorerConfigPreviewFactory(),
        );

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: null,
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

        self::assertSame(AnalysisDimensionGrain::Total, $normalized->timeGrain);
    }
}
