<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationDistributionSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;

final class GenericAllocationDistributionSqlBuilderTest extends TestCase
{
    private GenericAllocationDistributionSqlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GenericAllocationDistributionSqlBuilder(
            new DimensionRegistry(),
            new GenericAnalysisScopeSqlFilter(),
        );
    }

    public function testBuildsTransportTimeDistributionByUrgency(): void
    {
        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertStringContainsString('urgency_code AS bucket', $sql);
        self::assertStringContainsString('EXTRACT(EPOCH FROM (arrival_at - created_at)) / 60.0 AS value', $sql);
        self::assertStringContainsString('FROM allocation_stats_projection', $sql);
        self::assertStringContainsString('arrival_at IS NOT NULL', $sql);
        self::assertStringContainsString('created_at IS NOT NULL', $sql);
        self::assertSame([], $params);
    }

    public function testAppliesPeriodFilter(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-01 00:00:00');

        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds($from, $to),
        ));

        self::assertStringContainsString('created_at >= :period_from', $sql);
        self::assertArrayHasKey('period_from', $params);
    }

    public function testBuildsTransportTimeDistributionWithSeriesDimension(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'gender',
        ));

        self::assertStringContainsString('urgency_code AS bucket', $sql);
        self::assertStringContainsString('gender_code AS series', $sql);
        self::assertStringContainsString('ORDER BY bucket, series', $sql);
    }

    public function testAggregateAgeGroupFilterUsesRawAgeCondition(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: [
                new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter(
                    dimensionKey: 'age_group',
                    operator: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator::Equals,
                    value: 'under_18',
                ),
            ],
        ));

        self::assertStringContainsString('age IS NOT NULL AND age < 18', $sql);
        self::assertStringNotContainsString('filter_age_group', $sql);
    }

    public function testInFilterUsesParameterizedDimensionExpression(): void
    {
        [$sql, $params, $types] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'urgency',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: [
                new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter(
                    dimensionKey: 'department',
                    operator: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator::In,
                    value: [10, 20],
                ),
            ],
        ));

        self::assertStringContainsString('department_id IN (:filter_department)', $sql);
        self::assertSame([10, 20], $params['filter_department']);
        self::assertArrayHasKey('filter_department', $types);
    }
}
