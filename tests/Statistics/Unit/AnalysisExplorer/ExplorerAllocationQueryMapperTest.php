<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricKeyMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class ExplorerAllocationQueryMapperTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    private ExplorerAllocationQueryMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerAllocationQueryMapper(new ExplorerMetricKeyMapper());
    }

    public function testTimeRowsMapToTemporalPrimaryKey(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
            null,
        ));

        self::assertSame('year', $mapped->primaryDimensionKey);
        self::assertNull($mapped->seriesDimensionKey);
        self::assertSame(['count'], $mapped->metricKeys);
    }

    public function testBreakdownRowsMapDimensionAsPrimary(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            null,
        ));

        self::assertSame('gender', $mapped->primaryDimensionKey);
        self::assertNull($mapped->seriesDimensionKey);
    }

    public function testTimeRowsWithBreakdownColumnsMapBothAxes(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
        ));

        self::assertSame('month', $mapped->primaryDimensionKey);
        self::assertSame('gender', $mapped->seriesDimensionKey);
    }

    public function testBreakdownRowsWithTimeColumnsMapBothAxes(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::AgeGroup),
            AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
        ));

        self::assertSame('age_group', $mapped->primaryDimensionKey);
        self::assertSame('month', $mapped->seriesDimensionKey);
    }

    public function testQuarterColumnsMapToQuarterRegistryKey(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Quarter),
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
        ));

        self::assertSame('quarter', $mapped->primaryDimensionKey);
        self::assertSame('urgency', $mapped->seriesDimensionKey);
    }

    public function testMapsFiltersToGenericQuery(): void
    {
        $mapped = $this->mapper->map($this->query(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            null,
            filters: [
                new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter(
                    dimensionKey: 'urgency',
                    operator: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator::Equals,
                    value: 1,
                ),
            ],
        ));

        self::assertCount(1, $mapped->filters);
        self::assertSame('urgency', $mapped->filters[0]->dimensionKey);
    }

    /**
     * @param list<\App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter> $filters
     */
    private function query(AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis, array $filters = []): AnalysisQuery
    {
        return new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: $filters,
        );
    }
}
