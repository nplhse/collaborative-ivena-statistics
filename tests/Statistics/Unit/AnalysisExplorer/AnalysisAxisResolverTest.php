<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisAxisResolver;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class AnalysisAxisResolverTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testClampsMonthGrainToDayWhenPeriodIsMonth(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            $capabilities,
            StatisticsFilterPeriod::Month,
        );

        self::assertSame(AnalysisDimensionGrain::Day, $resolved->resolvedGrain());
    }

    public function testClampsYearGrainToMonthWhenPeriodIsYear(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
            $capabilities,
            StatisticsFilterPeriod::Year,
        );

        self::assertSame(AnalysisDimensionGrain::Month, $resolved->resolvedGrain());
    }

    public function testClampsQuarterGrainToMonthWhenPeriodIsQuarter(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Quarter),
            $capabilities,
            StatisticsFilterPeriod::Quarter,
        );

        self::assertSame(AnalysisDimensionGrain::Month, $resolved->resolvedGrain());
    }

    public function testKeepsWeekGrainWithinAYearPeriod(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Week),
            $capabilities,
            StatisticsFilterPeriod::Year,
        );

        self::assertSame(AnalysisDimensionGrain::Week, $resolved->resolvedGrain());
    }

    public function testDoesNotClampYearGrainForAllTime(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
            $capabilities,
            StatisticsFilterPeriod::AllTime,
        );

        self::assertSame(AnalysisDimensionGrain::Year, $resolved->resolvedGrain());
    }

    public function testDoesNotClampBreakdownAxes(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $resolver = new AnalysisAxisResolver();

        $resolved = $resolver->resolve(
            new AnalysisAxisRef(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month),
            $capabilities,
            StatisticsFilterPeriod::Month,
        );

        self::assertSame(AnalysisDimensionGrain::Month, $resolved->resolvedGrain());
    }
}
