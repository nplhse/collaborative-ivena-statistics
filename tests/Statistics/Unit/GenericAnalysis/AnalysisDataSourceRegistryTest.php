<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisPeriodAppliesTo;
use App\Statistics\GenericAnalysis\Registry\AnalysisDataSourceRegistry;
use PHPUnit\Framework\TestCase;

final class AnalysisDataSourceRegistryTest extends TestCase
{
    public function testAllocationsDefinition(): void
    {
        $definition = new AnalysisDataSourceRegistry()->get(AnalysisDataSource::Allocations);

        self::assertSame('count', $definition->distributionBaseMetricKey);
        self::assertSame('month', $definition->defaultPrimaryDimensionKey);
        self::assertFalse($definition->supportsPopulationModifier);
        self::assertSame(AnalysisPeriodAppliesTo::AllMetrics, $definition->periodAppliesTo);
    }

    public function testHospitalsDefinition(): void
    {
        $definition = new AnalysisDataSourceRegistry()->get(AnalysisDataSource::Hospitals);

        self::assertSame('hospital_count', $definition->distributionBaseMetricKey);
        self::assertSame('hospital_tier', $definition->defaultPrimaryDimensionKey);
        self::assertTrue($definition->supportsPopulationModifier);
        self::assertSame(AnalysisPeriodAppliesTo::AllocationDerivedOnly, $definition->periodAppliesTo);
    }
}
