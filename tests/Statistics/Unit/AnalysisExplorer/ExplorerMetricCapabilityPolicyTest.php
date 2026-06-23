<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
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

final class ExplorerMetricCapabilityPolicyTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    private ExplorerMetricCapabilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = $this->createExplorerMetricCapabilityPolicy();
    }

    public function testCanShowPercentOfTotalForGenderBreakdown(): void
    {
        $config = $this->config(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            columnAxis: null,
        );

        self::assertTrue($this->policy->canShowPercentOfTotal($config));
    }

    public function testCanShowPercentOfTotalWithColumnAxis(): void
    {
        $config = $this->config(
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
        );

        self::assertTrue($this->policy->canShowPercentOfTotal($config));
    }

    public function testCanShowPercentOfTotalForTemporalRows(): void
    {
        $config = $this->config(
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
        );

        self::assertTrue($this->policy->canShowPercentOfTotal($config));
    }

    public function testNormalizeMetricKeysDropsPercentWhenRatePresent(): void
    {
        $config = $this->config(
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
        );

        $normalized = $this->policy->normalizeMetricKeys([
            AnalysisMetricKey::AllocationCount,
            AnalysisMetricKey::ResusRate,
            AnalysisMetricKey::PercentOfTotal,
        ], $config);

        self::assertSame(
            [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::ResusRate],
            $normalized,
        );
    }

    public function testNormalizeMetricKeysEnsuresAllocationCountWithPercent(): void
    {
        $config = $this->config(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            columnAxis: null,
        );

        $normalized = $this->policy->normalizeMetricKeys([
            AnalysisMetricKey::PercentOfTotal,
        ], $config);

        self::assertSame(
            [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            $normalized,
        );
    }

    private function config(AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Test',
        );
    }
}
