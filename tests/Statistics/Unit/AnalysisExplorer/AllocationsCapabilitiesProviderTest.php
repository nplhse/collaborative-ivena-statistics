<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;

final class AllocationsCapabilitiesProviderTest extends TestCase
{
    public function testCapabilitiesIncludeExpectedDimensionsAndGrains(): void
    {
        $capabilities = new AllocationsCapabilitiesProvider()->capabilities();

        self::assertContains(AnalysisDimensionKey::Time, $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::Gender, $capabilities->dimensions);
        self::assertContains(AnalysisDimensionKey::Urgency, $capabilities->dimensions);
        self::assertSame(
            [AnalysisDimensionGrain::Month, AnalysisDimensionGrain::Year],
            $capabilities->timeGrainsFor(AnalysisDimensionKey::Time),
        );
        self::assertSame([], $capabilities->timeGrainsFor(AnalysisDimensionKey::Gender));
    }

    public function testSupportsValidTimeConfiguration(): void
    {
        $capabilities = new AllocationsCapabilitiesProvider()->capabilities();
        $config = $this->createConfig($capabilities, AnalysisDimensionKey::Time, AnalysisDimensionGrain::Month);

        self::assertTrue($capabilities->supports($config));
    }

    private function createConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimension,
        ?AnalysisDimensionGrain $grain,
    ): \App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig {
        return new \App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $capabilities->defaultMetric,
            dimensionKey: $dimension,
            timeGrain: $grain,
            statisticsFilter: new \App\Statistics\Application\DTO\StatisticsFilter(
                scope: \App\Statistics\Application\DTO\StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: \App\Statistics\Application\DTO\StatisticsFilterPeriod::All,
            ),
            presentation: new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(
                chartType: $capabilities->defaultChartType,
            ),
            title: 'Test',
        );
    }
}
