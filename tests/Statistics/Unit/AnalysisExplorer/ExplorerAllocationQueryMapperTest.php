<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricKeyMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use PHPUnit\Framework\TestCase;

final class ExplorerAllocationQueryMapperTest extends TestCase
{
    private ExplorerAllocationQueryMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerAllocationQueryMapper(new ExplorerMetricKeyMapper());
    }

    public function testTimeDimensionMapsToTemporalPrimaryKey(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisDimensionKey::Time,
            AnalysisDimensionGrain::Year,
        ));

        self::assertSame('year', $mapped->primaryDimensionKey);
        self::assertNull($mapped->seriesDimensionKey);
        self::assertSame(['count'], $mapped->metricKeys);
    }

    public function testBreakdownTotalMapsDimensionAsPrimary(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisDimensionKey::Gender,
            AnalysisDimensionGrain::Total,
        ));

        self::assertSame('gender', $mapped->primaryDimensionKey);
        self::assertNull($mapped->seriesDimensionKey);
    }

    public function testBreakdownOverTimeMapsTemporalPrimaryAndSeriesDimension(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisDimensionKey::Gender,
            AnalysisDimensionGrain::Month,
        ));

        self::assertSame('month', $mapped->primaryDimensionKey);
        self::assertSame('gender', $mapped->seriesDimensionKey);
    }

    public function testQuarterGrainMapsToQuarterRegistryKey(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisDimensionKey::Urgency,
            AnalysisDimensionGrain::Quarter,
        ));

        self::assertSame('quarter', $mapped->primaryDimensionKey);
        self::assertSame('urgency', $mapped->seriesDimensionKey);
    }

    private function query(
        AnalysisDimensionKey $dimensionKey,
        AnalysisDimensionGrain $timeGrain,
    ): AnalysisQuery {
        return new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: $dimensionKey,
            timeGrain: $timeGrain,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );
    }
}
