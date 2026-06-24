<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDistributionValueSource;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalDistributionSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;

final class GenericHospitalDistributionSqlBuilderTest extends TestCase
{
    private GenericHospitalDistributionSqlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GenericHospitalDistributionSqlBuilder(
            new DimensionRegistry(),
            new GenericHospitalScopeSqlFilter(),
            new GenericAnalysisScopeSqlFilter(),
        );
    }

    public function testBuildsBedsDistributionByTier(): void
    {
        [$sql, $params] = $this->builder->build(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital_tier',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
                dataSource: AnalysisDataSource::Hospitals,
            ),
            ExplorerDistributionValueSource::HospitalBeds,
        );

        self::assertStringContainsString('h.tier AS bucket', $sql);
        self::assertStringContainsString('h.beds::DOUBLE PRECISION AS value', $sql);
        self::assertStringContainsString('FROM hospital h', $sql);
        self::assertStringNotContainsString('alloc.cnt', $sql);
        self::assertSame([], $params);
    }

    public function testBuildsAllocationsPerHospitalDistributionWithPeriodFilter(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-01 00:00:00');

        [$sql, $params] = $this->builder->build(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital_tier',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds($from, $to),
                dataSource: AnalysisDataSource::Hospitals,
            ),
            ExplorerDistributionValueSource::AllocationsPerHospital,
        );

        self::assertStringContainsString('COALESCE(alloc.cnt, 0)::DOUBLE PRECISION AS value', $sql);
        self::assertStringContainsString('LEFT JOIN (', $sql);
        self::assertStringContainsString('created_at >= :period_from', $sql);
        self::assertArrayHasKey('period_from', $params);
    }

    public function testParticipatingModeFiltersHospitals(): void
    {
        [$sql] = $this->builder->build(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital_tier',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
                dataSource: AnalysisDataSource::Hospitals,
                hospitalPopulationMode: HospitalPopulationMode::Participating,
            ),
            ExplorerDistributionValueSource::HospitalBeds,
        );

        self::assertStringContainsString('h.is_participating = true', $sql);
    }

    public function testBuildsMedianTransportTimePerHospitalDistribution(): void
    {
        [$sql, $params] = $this->builder->build(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital_tier',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
                dataSource: AnalysisDataSource::Hospitals,
            ),
            ExplorerDistributionValueSource::HospitalMedianTransportTime,
        );

        self::assertStringContainsString('alloc.median_transport::DOUBLE PRECISION AS value', $sql);
        self::assertStringContainsString('PERCENTILE_CONT(0.5)', $sql);
        self::assertStringContainsString('arrival_at IS NOT NULL', $sql);
        self::assertSame([], $params);
    }

    public function testBuildsBedsDistributionWithSeriesAndCompareMode(): void
    {
        [$sql] = $this->builder->build(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital_tier',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
                seriesDimensionKey: 'hospital_population_group',
                dataSource: AnalysisDataSource::Hospitals,
                hospitalPopulationMode: HospitalPopulationMode::Compare,
            ),
            ExplorerDistributionValueSource::HospitalBeds,
        );

        self::assertStringContainsString('g.population_group AS series', $sql);
        self::assertStringContainsString('CROSS JOIN (VALUES', $sql);
        self::assertStringContainsString('h.is_participating = true', $sql);
        self::assertStringContainsString('ORDER BY bucket, series', $sql);
    }
}
