<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

final readonly class GetOverviewDashboardMetricsQuery
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    public function __invoke(OverviewQueryCriteria $criteria): OverviewDashboardMetricsResult
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return OverviewDashboardMetricsResult::empty();
        }

        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $scopedHospitalSql = $this->scopedHospitalSql($criteria->hospitalIds);

        $shockExpr = $hasExtended
            ? $this->scopedCountFilter('is_shock = true', $scopedHospitalSql)
            : '0';
        $pregnantExpr = $hasExtended
            ? $this->scopedCountFilter('is_pregnant = true', $scopedHospitalSql)
            : '0';
        $workAccidentExpr = $hasExtended
            ? $this->scopedCountFilter('is_work_accident = true', $scopedHospitalSql)
            : '0';

        $genderSelects = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $code) {
            $alias = match ($code) {
                AllocationStatsGenderProjectionCode::Male => 'gender_m',
                AllocationStatsGenderProjectionCode::Female => 'gender_f',
                AllocationStatsGenderProjectionCode::Other => 'gender_x',
            };
            $genderSelects[] = sprintf(
                '%s::int AS %s',
                $this->scopedCountFilter(sprintf('gender_code = %d', $code->value), $scopedHospitalSql),
                $alias,
            );
        }

        $urgencySelects = [];
        foreach (AllocationStatsUrgencyProjectionCode::cases() as $code) {
            $urgencySelects[] = sprintf(
                '%s::int AS urgency_%d',
                $this->scopedCountFilter(sprintf('urgency_code = %d', $code->value), $scopedHospitalSql),
                $code->value,
            );
        }

        $ageGroupSelects = [];
        $ageBucketCase = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        foreach (StatisticsAgeGroupBucketSql::BUCKET_KEYS as $bucketKey) {
            $alias = 'age_group_'.str_replace('-', '_', $bucketKey);
            $ageGroupSelects[] = sprintf(
                '%s::int AS %s',
                $this->scopedCountFilter(sprintf('(%s) = %s', $ageBucketCase, $this->connection->quote($bucketKey)), $scopedHospitalSql),
                $alias,
            );
        }

        $nightCode = AllocationStatsDayTimeBucketProjectionCode::Night->value;
        $medianAgeExpr = 'PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age) FILTER (WHERE age IS NOT NULL)';
        $medianTransportExpr = sprintf(
            '%s FILTER (WHERE arrival_at IS NOT NULL AND created_at IS NOT NULL)',
            StatisticsTransportTimeSql::medianPreciseMinutes(),
        );

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);

        $sql = sprintf(
            <<<'SQL'
SELECT
    COUNT(*)::int AS platform_total,
    %s::int AS scoped_total,
    %s::int AS with_physician,
    %s::int AS cpr,
    %s::int AS ventilated,
    %s::int AS shock,
    %s::int AS pregnant,
    %s::int AS work_accident,
    %s::int AS infectious,
    %s::int AS cathlab,
    %s::int AS resus,
    %s::int AS night_daytime,
    %s::int AS weekend,
    %s AS median_age,
    %s AS median_transport_minutes,
    %s
FROM allocation_stats_projection
WHERE %s
SQL,
            $this->scopedCountFilter('true', $scopedHospitalSql),
            $this->scopedCountFilter('is_with_physician = true', $scopedHospitalSql),
            $this->scopedCountFilter('is_cpr = true', $scopedHospitalSql),
            $this->scopedCountFilter('is_ventilated = true', $scopedHospitalSql),
            $shockExpr,
            $pregnantExpr,
            $workAccidentExpr,
            $this->scopedCountFilter('infection_id IS NOT NULL', $scopedHospitalSql),
            $this->scopedCountFilter('requires_cathlab = true', $scopedHospitalSql),
            $this->scopedCountFilter('requires_resus = true', $scopedHospitalSql),
            $this->scopedCountFilter(sprintf('day_time_bucket_code = %d', $nightCode), $scopedHospitalSql),
            $this->scopedCountFilter('created_weekday IN (6, 7)', $scopedHospitalSql),
            $medianAgeExpr,
            $medianTransportExpr,
            implode(",\n    ", [...$genderSelects, ...$urgencySelects, ...$ageGroupSelects]),
            $where,
        );

        $fetched = $this->connection->fetchAssociative($sql, $params);
        /** @var array<string, int|string|null> $row */
        $row = false === $fetched ? [] : $fetched;

        $ageGroupCounts = [];
        foreach (StatisticsAgeGroupBucketSql::BUCKET_KEYS as $bucketKey) {
            $alias = 'age_group_'.str_replace('-', '_', $bucketKey);
            $ageGroupCounts[$bucketKey] = (int) ($row[$alias] ?? 0);
        }

        return new OverviewDashboardMetricsResult(
            (int) ($row['platform_total'] ?? 0),
            (int) ($row['scoped_total'] ?? 0),
            (int) ($row['with_physician'] ?? 0),
            (int) ($row['cpr'] ?? 0),
            (int) ($row['ventilated'] ?? 0),
            (int) ($row['shock'] ?? 0),
            (int) ($row['pregnant'] ?? 0),
            (int) ($row['work_accident'] ?? 0),
            (int) ($row['infectious'] ?? 0),
            (int) ($row['cathlab'] ?? 0),
            (int) ($row['resus'] ?? 0),
            [
                'M' => (int) ($row['gender_m'] ?? 0),
                'F' => (int) ($row['gender_f'] ?? 0),
                'X' => (int) ($row['gender_x'] ?? 0),
            ],
            [
                AllocationStatsUrgencyProjectionCode::Emergency->value => (int) ($row['urgency_1'] ?? 0),
                AllocationStatsUrgencyProjectionCode::Inpatient->value => (int) ($row['urgency_2'] ?? 0),
                AllocationStatsUrgencyProjectionCode::Outpatient->value => (int) ($row['urgency_3'] ?? 0),
            ],
            (int) ($row['night_daytime'] ?? 0),
            (int) ($row['weekend'] ?? 0),
            $this->toFloatOrNull($row['median_age'] ?? null),
            $this->toFloatOrNull($row['median_transport_minutes'] ?? null),
            $ageGroupCounts,
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    private function scopedHospitalSql(?array $hospitalIds): ?string
    {
        if (null === $hospitalIds) {
            return null;
        }

        $ids = array_map(static fn (int $id): int => $id, $hospitalIds);

        return 'hospital_id IN ('.implode(',', $ids).')';
    }

    private function scopedCountFilter(string $condition, ?string $scopedHospitalSql): string
    {
        if (null === $scopedHospitalSql) {
            return sprintf('COUNT(*) FILTER (WHERE %s)', $condition);
        }

        return sprintf('COUNT(*) FILTER (WHERE %s AND %s)', $condition, $scopedHospitalSql);
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
