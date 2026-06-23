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
        self::assertTrue($this->catalog->has(AnalysisMetricKey::AllocationCount, AnalysisDataSourceKey::Allocations));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::ResusRate, AnalysisDataSourceKey::Allocations));
    }

    public function testGetReturnsDefinitionLinkedToRegistry(): void
    {
        $definition = $this->catalog->get(AnalysisMetricKey::AllocationCount, AnalysisDataSourceKey::Allocations);

        self::assertSame(AnalysisMetricKey::AllocationCount, $definition->explorerKey);
        self::assertSame('count', $definition->registryKey());
        self::assertTrue($definition->enabled);
    }

    public function testStatisticalMetricsExistButAreDisabledInStepOne(): void
    {
        $definition = $this->catalog->get(AnalysisMetricKey::MedianTransportTime, AnalysisDataSourceKey::Allocations);

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

    public function testHospitalMetricsAreRegisteredForHospitalsDataSource(): void
    {
        self::assertTrue($this->catalog->has(AnalysisMetricKey::HospitalCount, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::AvgBeds, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::SumBeds, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::MinBeds, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::MaxBeds, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::TotalAllocations, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::AvgAllocationsPerHospital, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::MinAllocations, AnalysisDataSourceKey::Hospitals));
        self::assertTrue($this->catalog->has(AnalysisMetricKey::MaxAllocations, AnalysisDataSourceKey::Hospitals));
        self::assertFalse($this->catalog->has(AnalysisMetricKey::AllocationCount, AnalysisDataSourceKey::Hospitals));
    }

    public function testAllHospitalMetricsIncludingDistributionProfilesAreEnabled(): void
    {
        $enabled = $this->catalog->enabledKeysForDataSource(AnalysisDataSourceKey::Hospitals);

        self::assertContains(AnalysisMetricKey::HospitalCount, $enabled);
        self::assertContains(AnalysisMetricKey::BedsDistribution, $enabled);
        self::assertContains(AnalysisMetricKey::AllocationsPerHospitalDistribution, $enabled);
        self::assertContains(AnalysisMetricKey::MaxAllocations, $enabled);
        self::assertCount(12, $enabled);
    }

    public function testDistributionProfilesHaveNoGaDefinition(): void
    {
        $definition = $this->catalog->get(AnalysisMetricKey::BedsDistribution, AnalysisDataSourceKey::Hospitals);

        self::assertNull($definition->gaDefinition);
        self::assertTrue($definition->isChartable());
    }
}
