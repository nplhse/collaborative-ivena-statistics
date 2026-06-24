<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class GenericHospitalAnalysisSqlBuilderTest extends TestCase
{
    private GenericHospitalAnalysisSqlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GenericHospitalAnalysisSqlBuilder(
            new DimensionRegistry(),
            new MetricRegistry(),
            new GenericHospitalScopeSqlFilter(),
            new GenericAnalysisScopeSqlFilter(),
        );
    }

    public function testBuildsHospitalCountByTier(): void
    {
        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'hospital_tier',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            dataSource: AnalysisDataSource::Hospitals,
        ));

        self::assertStringContainsString('h.tier AS bucket', $sql);
        self::assertStringContainsString('COUNT(DISTINCT h.id)::INT AS hospital_count', $sql);
        self::assertStringContainsString('FROM hospital h', $sql);
        self::assertStringContainsString('allocation_stats_projection', $sql);
        self::assertStringNotContainsString('is_participating = true', $sql);
        self::assertSame([], $params);
    }

    public function testParticipatingModeFiltersHospitals(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'hospital_tier',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Participating,
        ));

        self::assertStringContainsString('h.is_participating = true', $sql);
    }

    public function testCompareModeUsesPopulationGroupSeries(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'hospital_tier',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'hospital_population_group',
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Compare,
        ));

        self::assertStringContainsString("CROSS JOIN (VALUES ('participating'), ('non_participating')) AS g(population_group)", $sql);
        self::assertStringContainsString('g.population_group AS series', $sql);
        self::assertStringContainsString("WHEN g.population_group = 'participating' AND h.is_participating THEN h.id", $sql);
        self::assertStringContainsString("WHEN g.population_group = 'non_participating' AND NOT h.is_participating THEN h.id", $sql);
    }

    public function testPeriodFilterAppliesOnlyToAllocationSubquery(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-01 00:00:00');

        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'hospital_tier',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds($from, $to),
            metricKeys: ['hospital_count', 'total_allocations'],
            dataSource: AnalysisDataSource::Hospitals,
        ));

        self::assertStringContainsString('created_at >= :period_from', $sql);
        self::assertStringContainsString('COALESCE(SUM(alloc.cnt), 0)::INT AS total_allocations', $sql);
        self::assertArrayHasKey('period_from', $params);
    }
}
