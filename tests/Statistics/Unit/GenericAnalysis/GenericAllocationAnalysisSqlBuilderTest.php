<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class GenericAllocationAnalysisSqlBuilderTest extends TestCase
{
    private GenericAllocationAnalysisSqlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GenericAllocationAnalysisSqlBuilder(
            new DimensionRegistry(),
            new MetricRegistry(),
            new GenericAnalysisScopeSqlFilter(),
        );
    }

    public function testBuildsParameterizedCountByMonth(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-01 00:00:00');

        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: new StatisticsScopeCriteria([10, 20]),
            periodBounds: new StatisticsPeriodBounds($from, $to),
        ));

        self::assertStringContainsString('created_month AS bucket', $sql);
        self::assertStringContainsString('COUNT(*)::INT AS count', $sql);
        self::assertStringContainsString('FROM allocation_stats_projection', $sql);
        self::assertStringContainsString('hospital_id IN (:scope_hospital_ids)', $sql);
        self::assertStringContainsString('created_at >= :period_from', $sql);
        self::assertStringContainsString('created_at < :period_to_exclusive', $sql);
        self::assertStringNotContainsString(';', $sql);
        self::assertStringNotContainsString('age IS NOT NULL', $sql);
        self::assertSame([10, 20], $params['scope_hospital_ids']);
    }

    public function testRejectsUnknownDimensionKey(): void
    {
        $this->expectException(UnknownAnalysisDimensionException::class);

        $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'evil_column',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));
    }

    public function testSeriesDimensionAddsGroupBy(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'urgency',
        ));

        self::assertStringContainsString('urgency_code AS series', $sql);
        self::assertStringContainsString('GROUP BY bucket, series', $sql);
    }

    public function testHospitalCohortDimensionUsesCaseExpression(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'hospital_cohort',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertStringContainsString('CASE', $sql);
        self::assertStringContainsString("'urban_basic'", $sql);
        self::assertStringContainsString("'rural_basic'", $sql);
        self::assertStringContainsString('hospital_location_code', $sql);
        self::assertStringContainsString('hospital_tier_code', $sql);
    }

    public function testCohortScopeCriteriaAddsLocationAndTierFilters(): void
    {
        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: new StatisticsScopeCriteria(
                [1, 2, 3],
                [AllocationStatsHospitalLocationProjectionCode::Rural->value],
                [AllocationStatsHospitalTierProjectionCode::Basic->value],
                new HospitalCohortKey(\App\Allocation\Domain\Enum\HospitalLocation::RURAL, \App\Allocation\Domain\Enum\HospitalTier::BASIC),
            ),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertStringContainsString('hospital_id IN (:scope_hospital_ids)', $sql);
        self::assertStringContainsString('hospital_location_code IN (:scope_location_codes)', $sql);
        self::assertStringContainsString('hospital_tier_code IN (:scope_tier_codes)', $sql);
        self::assertSame([1, 2, 3], $params['scope_hospital_ids']);
        self::assertSame(
            [AllocationStatsHospitalLocationProjectionCode::Rural->value],
            $params['scope_location_codes'],
        );
        self::assertSame(
            [AllocationStatsHospitalTierProjectionCode::Basic->value],
            $params['scope_tier_codes'],
        );
    }

    public function testExcludesAgeGroupUnknownWhenNullBucketsDisabled(): void
    {
        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'age_group',
            includeNullBuckets: false,
        ));

        self::assertStringContainsString('age IS NOT NULL', $sql);
        self::assertStringContainsString('NOT IN (:exclude_null_bucket_age_group)', $sql);
        self::assertSame(['unknown'], $params['exclude_null_bucket_age_group']);
    }

    public function testIncludesTransportMetricsInSql(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'department',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['count', 'median_transport_time', 'p90_transport_time'],
        ));

        self::assertStringContainsString('COUNT(*)::INT AS count', $sql);
        self::assertStringContainsString('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (arrival_at - created_at))', $sql);
        self::assertStringContainsString('PERCENTILE_CONT(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (arrival_at - created_at))', $sql);
        self::assertStringNotContainsString('age IS NOT NULL', $sql);
    }

    public function testIncludesRateMetricsInSql(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'department',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['count', 'resus_rate'],
        ));

        self::assertStringContainsString('COUNT(*) FILTER (WHERE requires_resus IS TRUE)', $sql);
        self::assertStringContainsString('NULLIF(COUNT(*), 0)', $sql);
        self::assertStringContainsString('AS resus_rate', $sql);
    }

    public function testBuildsClinicalResourcesUnpivotSql(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'clinical_resources',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['prevalence_rate'],
        ));

        self::assertStringContainsString('CROSS JOIN (VALUES (\'resus\'), (\'cathlab\')) AS i(indicator_key)', $sql);
        self::assertStringContainsString('i.indicator_key AS bucket', $sql);
        self::assertStringContainsString('a.requires_resus IS TRUE', $sql);
        self::assertStringContainsString('a.requires_cathlab IS TRUE', $sql);
        self::assertStringContainsString('AS prevalence_rate', $sql);
        self::assertStringContainsString('FROM allocation_stats_projection a', $sql);
    }

    public function testClinicalUnpivotQualifiesScopeColumnsWithoutBreakingParameterNames(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-01 00:00:00');

        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'clinical_resources',
            scopeCriteria: new StatisticsScopeCriteria([10, 20]),
            periodBounds: new StatisticsPeriodBounds($from, $to),
            metricKeys: ['prevalence_rate'],
        ));

        self::assertStringContainsString('a.hospital_id IN (:scope_hospital_ids)', $sql);
        self::assertStringContainsString('a.created_at >= :period_from', $sql);
        self::assertStringContainsString('a.created_at < :period_to_exclusive', $sql);
        self::assertStringNotContainsString(':scope_a.', $sql);
        self::assertSame([10, 20], $params['scope_hospital_ids']);
    }

    public function testBuildsClinicalResourcesAsSeriesColumn(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'gender',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'clinical_resources',
            metricKeys: ['prevalence_rate'],
        ));

        self::assertStringContainsString('a.gender_code AS bucket', $sql);
        self::assertStringContainsString('i.indicator_key AS series', $sql);
        self::assertStringContainsString("CROSS JOIN (VALUES ('resus'), ('cathlab')) AS i(indicator_key)", $sql);
        self::assertStringContainsString('FROM allocation_stats_projection a', $sql);
        self::assertStringContainsString('AS prevalence_rate', $sql);
    }

    public function testBuildsClinicalFeaturesUnpivotSqlWithSeries(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'clinical_features',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'gender',
            metricKeys: ['count', 'prevalence_rate'],
        ));

        self::assertStringContainsString('a.gender_code AS series', $sql);
        self::assertStringContainsString('GROUP BY bucket, series', $sql);
        self::assertStringContainsString('a.infection_id IS NOT NULL', $sql);
        self::assertStringContainsString('COUNT(*) FILTER (WHERE CASE i.indicator_key', $sql);
    }

    public function testEmptyHospitalScopeUsesImpossibleCondition(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: new StatisticsScopeCriteria([]),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertStringContainsString('1 = 0', $sql);
    }

    public function testFilterValuesAreParameterizedAgainstInjection(): void
    {
        [$sql, $params] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: [
                new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter(
                    dimensionKey: 'urgency',
                    operator: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator::Equals,
                    value: "'; DROP TABLE allocation_stats_projection; --",
                ),
            ],
        ));

        self::assertStringNotContainsString('DROP TABLE', $sql);
        self::assertContains("'; DROP TABLE allocation_stats_projection; --", $params);
    }

    public function testAggregateAgeGroupFilterUsesRawAgeCondition(): void
    {
        [$sql] = $this->builder->build(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: [
                new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter(
                    dimensionKey: 'age_group',
                    operator: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator::Equals,
                    value: 'over_80',
                ),
            ],
        ));

        self::assertStringContainsString('age >= 80', $sql);
        self::assertStringNotContainsString('filter_age_group', $sql);
    }
}
