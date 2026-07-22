<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Application\HospitalsDistributionResultMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HospitalsDistributionResultMapperTest extends TestCase
{
    public function testMapsRawHospitalValuesIntoBoxPlotRowsPerBucket(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $entityLabelResolver = $this->createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $mapper = new HospitalsDistributionResultMapper(
            new DimensionRegistry(),
            new AnalysisDimensionLabelResolver(
                $translator,
                $entityLabelResolver,
                new HospitalCohortLabelResolver($translator),
            ),
            new DescriptiveStatisticsCalculator(),
            new ExplorerMetricProfileRegistry(),
        );

        $gaQuery = new GenericAnalysisQuery(
            primaryDimensionKey: 'hospital_tier',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['beds_distribution'],
        );
        $query = new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );

        $rows = $mapper->map([
            ['bucket' => 'tier_a', 'value' => 10],
            ['bucket' => 'tier_a', 'value' => 30],
            ['bucket' => 'tier_b', 'value' => 100],
        ], $gaQuery, $query);

        self::assertCount(2, $rows);
        self::assertSame('tier_a', $rows[0]->bucket);
        self::assertNotNull($rows[0]->boxPlot);
        self::assertSame(2, $rows[0]->boxPlot->count);
        self::assertSame(20.0, $rows[0]->boxPlot->median);
        self::assertSame(100.0, $rows[1]->boxPlot->median);
    }

    public function testCastsNumericBucketKeysToString(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $entityLabelResolver = $this->createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $mapper = new HospitalsDistributionResultMapper(
            new DimensionRegistry(),
            new AnalysisDimensionLabelResolver(
                $translator,
                $entityLabelResolver,
                new HospitalCohortLabelResolver($translator),
            ),
            new DescriptiveStatisticsCalculator(),
            new ExplorerMetricProfileRegistry(),
        );

        $gaQuery = new GenericAnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['transport_time_distribution'],
        );
        $query = new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: null,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );

        $rows = $mapper->map([
            ['bucket' => 1, 'value' => 20.0],
            ['bucket' => 1, 'value' => 40.0],
        ], $gaQuery, $query);

        self::assertCount(1, $rows);
        self::assertSame('1', $rows[0]->bucket);
        self::assertSame(30.0, $rows[0]->boxPlot?->median);
    }

    public function testMapsRawValuesIntoBoxPlotRowsPerBucketAndSeries(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $entityLabelResolver = $this->createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $mapper = new HospitalsDistributionResultMapper(
            new DimensionRegistry(),
            new AnalysisDimensionLabelResolver(
                $translator,
                $entityLabelResolver,
                new HospitalCohortLabelResolver($translator),
            ),
            new DescriptiveStatisticsCalculator(),
            new ExplorerMetricProfileRegistry(),
        );

        $gaQuery = new GenericAnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'gender',
            metricKeys: ['transport_time_distribution'],
        );
        $query = new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );

        $rows = $mapper->map([
            ['bucket' => 1, 'series' => 1, 'value' => 20.0],
            ['bucket' => 1, 'series' => 1, 'value' => 40.0],
            ['bucket' => 1, 'series' => 2, 'value' => 60.0],
        ], $gaQuery, $query);

        self::assertCount(2, $rows);
        self::assertSame('1', $rows[0]->bucket);
        self::assertSame('1', $rows[0]->seriesKey);
        self::assertSame(30.0, $rows[0]->boxPlot?->median);
        self::assertSame('2', $rows[1]->seriesKey);
        self::assertSame(60.0, $rows[1]->boxPlot?->median);
    }
}
