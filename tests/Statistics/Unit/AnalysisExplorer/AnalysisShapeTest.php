<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisShape;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;

final class AnalysisShapeTest extends TestCase
{
    public function testDistributionWhenTheRowIsCategoricalAndHasNoColumn(): void
    {
        $config = $this->config(AnalysisDimensionKey::AgeGroup, null, AnalysisMetricKey::AllocationCount);

        self::assertSame(AnalysisShape::Distribution, AnalysisShape::fromConfig($config));
    }

    public function testTimeSeriesWhenTheRowIsTimeEvenWithAColumn(): void
    {
        $config = $this->config(
            AnalysisDimensionKey::Time,
            new AnalysisAxisRef(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Total),
            AnalysisMetricKey::AllocationCount,
            AnalysisDimensionGrain::Month,
        );

        self::assertSame(AnalysisShape::TimeSeries, AnalysisShape::fromConfig($config));
    }

    public function testMatrixWhenAColumnIsSetAndTheRowIsNotTime(): void
    {
        $config = $this->config(
            AnalysisDimensionKey::Gender,
            new AnalysisAxisRef(AnalysisDimensionKey::AgeGroup, AnalysisDimensionGrain::Total),
            AnalysisMetricKey::AllocationCount,
        );

        self::assertSame(AnalysisShape::Matrix, AnalysisShape::fromConfig($config));
    }

    public function testDistributionProfileWinsOverATimeRow(): void
    {
        $config = $this->config(
            AnalysisDimensionKey::Time,
            null,
            AnalysisMetricKey::AllocationsPerHospitalDistribution,
            AnalysisDimensionGrain::Month,
        );

        self::assertSame(AnalysisShape::Distribution, AnalysisShape::fromConfig($config));
    }

    private function config(
        AnalysisDimensionKey $row,
        ?AnalysisAxisRef $column,
        AnalysisMetricKey $metric,
        AnalysisDimensionGrain $grain = AnalysisDimensionGrain::Total,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [$metric],
            visualMetricKey: $metric,
            rowAxis: new AnalysisAxisRef($row, $grain),
            columnAxis: $column,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Shape',
        );
    }
}
