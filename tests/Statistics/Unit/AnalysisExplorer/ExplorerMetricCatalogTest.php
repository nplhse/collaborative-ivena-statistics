<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCatalog;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class ExplorerMetricCatalogTest extends TestCase
{
    private ExplorerMetricCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ExplorerMetricCatalog(new MetricRegistry());
    }

    public function testHasReturnsTrueForRegisteredAllocationMetrics(): void
    {
        self::assertTrue($this->catalog->has(AnalysisMetricKey::AllocationCount));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::ResusRate));
    }

    public function testGetReturnsDefinitionLinkedToRegistry(): void
    {
        $definition = $this->catalog->get(AnalysisMetricKey::AllocationCount);

        self::assertSame(AnalysisMetricKey::AllocationCount, $definition->explorerKey);
        self::assertSame('count', $definition->registryKey());
        self::assertTrue($definition->enabled);
    }

    public function testStatisticalMetricsExistButAreDisabledInStepOne(): void
    {
        $definition = $this->catalog->get(AnalysisMetricKey::MedianTransportTime);

        self::assertFalse($definition->enabled);
        self::assertFalse($definition->isChartable());
    }

    public function testEnabledKeysForAllocationsExcludeStatisticalMetrics(): void
    {
        $enabled = $this->catalog->enabledKeysForDataSource(AnalysisDataSourceKey::Allocations);

        self::assertContains(AnalysisMetricKey::AllocationCount, $enabled);
        self::assertContains(AnalysisMetricKey::PercentOfTotal, $enabled);
        self::assertNotContains(AnalysisMetricKey::MedianTransportTime, $enabled);
    }

    public function testAllForDataSourceIndexesByExplorerValue(): void
    {
        $metrics = $this->catalog->allForDataSource(AnalysisDataSourceKey::Allocations);

        self::assertArrayHasKey(AnalysisMetricKey::AllocationCount->value, $metrics);
        self::assertArrayHasKey(AnalysisMetricKey::ResusRate->value, $metrics);
    }
}
