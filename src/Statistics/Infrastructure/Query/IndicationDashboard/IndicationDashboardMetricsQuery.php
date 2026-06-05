<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

/**
 * Fetches indication vs. baseline metrics using two targeted scans:
 * 1) scope totals (full scope filter), 2) indication slice (scope + indication id).
 * Additive baseline counts are derived as scope - indication.
 *
 * @see docs/indication-dashboard-performance.md
 */
final readonly class IndicationDashboardMetricsQuery
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    public function fetch(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): IndicationDashboardMetricsRow {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return $this->emptyRow();
        }

        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $shockFilter = $hasExtended ? 'is_shock = true' : 'false';
        $pregnantFilter = $hasExtended ? 'is_pregnant = true' : 'false';
        $workAccidentFilter = $hasExtended ? 'is_work_accident = true' : 'false';

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);

        $countSelect = $this->countMetricSelectSql(
            $shockFilter,
            $pregnantFilter,
            $workAccidentFilter,
        );

        $scopeSql = <<<SQL
SELECT
    {$countSelect},
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age) FILTER (
        WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND age IS NOT NULL
    ) AS median_age_baseline,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes) FILTER (
        WHERE indication_normalized_id IS DISTINCT FROM :indication_id
    ) AS median_transport_baseline
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $scopeParams = array_merge($params, ['indication_id' => $indicationId]);
        $scopeRow = $this->connection->fetchAssociative($scopeSql, $scopeParams, $types);
        if (false === $scopeRow) {
            return $this->emptyRow();
        }

        $indicationWhere = $where.' AND indication_normalized_id = :indication_id';
        $indicationSql = <<<SQL
SELECT
    {$countSelect},
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age) FILTER (WHERE age IS NOT NULL) AS median_age_indication,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes) AS median_transport_indication
FROM allocation_stats_projection
WHERE {$indicationWhere}
SQL;

        $indicationRow = $this->connection->fetchAssociative($indicationSql, $scopeParams, $types);
        if (false === $indicationRow) {
            return $this->emptyRow();
        }

        return $this->mergeRows($scopeRow, $indicationRow);
    }

    private function countMetricSelectSql(
        string $shockFilter,
        string $pregnantFilter,
        string $workAccidentFilter,
    ): string {
        $nightCode = AllocationStatsDayTimeBucketProjectionCode::Night->value;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $emergencyCode = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatientCode = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatientCode = AllocationStatsUrgencyProjectionCode::Outpatient->value;
        $groundCode = AllocationStatsTransportTypeProjectionCode::Ground->value;
        $airCode = AllocationStatsTransportTypeProjectionCode::Air->value;

        return <<<SQL
    COUNT(*)::int AS total,
    COUNT(*) FILTER (WHERE is_with_physician = true)::int AS with_physician,
    COUNT(*) FILTER (WHERE requires_resus = true)::int AS resus,
    COUNT(*) FILTER (WHERE requires_cathlab = true)::int AS cathlab,
    COUNT(*) FILTER (WHERE urgency_code = {$emergencyCode})::int AS urgency_emergency,
    COUNT(*) FILTER (WHERE infection_id IS NOT NULL)::int AS infectious,
    COUNT(*) FILTER (WHERE is_cpr = true)::int AS cpr,
    COUNT(*) FILTER (WHERE is_ventilated = true)::int AS ventilated,
    COUNT(*) FILTER (WHERE {$shockFilter})::int AS shock,
    COUNT(*) FILTER (WHERE {$pregnantFilter})::int AS pregnant,
    COUNT(*) FILTER (WHERE {$workAccidentFilter})::int AS work_accident,
    COUNT(*) FILTER (WHERE day_time_bucket_code = {$nightCode})::int AS night_daytime,
    COUNT(*) FILTER (WHERE created_weekday IN (6, 7))::int AS weekend,
    COUNT(*) FILTER (WHERE age >= 80)::int AS age_80_plus,
    COUNT(*) FILTER (WHERE gender_code = {$maleCode})::int AS male,
    COUNT(*) FILTER (WHERE gender_code = {$femaleCode})::int AS female,
    COUNT(*) FILTER (WHERE transport_type_code = {$groundCode})::int AS ground_transport,
    COUNT(*) FILTER (WHERE transport_type_code = {$airCode})::int AS air_transport,
    COUNT(*) FILTER (WHERE urgency_code = {$inpatientCode})::int AS urgency_inpatient,
    COUNT(*) FILTER (WHERE urgency_code = {$outpatientCode})::int AS urgency_outpatient
SQL;
    }

    /**
     * @param array<string, mixed> $scopeRow
     * @param array<string, mixed> $indicationRow
     */
    private function mergeRows(array $scopeRow, array $indicationRow): IndicationDashboardMetricsRow
    {
        $countKeys = [
            'total',
            'with_physician',
            'resus',
            'cathlab',
            'urgency_emergency',
            'infectious',
            'cpr',
            'ventilated',
            'shock',
            'pregnant',
            'work_accident',
            'night_daytime',
            'weekend',
            'age_80_plus',
            'male',
            'female',
            'ground_transport',
            'air_transport',
            'urgency_inpatient',
            'urgency_outpatient',
        ];

        $counts = [];
        foreach ($countKeys as $key) {
            $scopeValue = (int) ($scopeRow[$key] ?? 0);
            $indicationValue = (int) ($indicationRow[$key] ?? 0);
            $counts[$key.'_indication'] = $indicationValue;
            $counts[$key.'_baseline'] = max(0, $scopeValue - $indicationValue);
        }

        return new IndicationDashboardMetricsRow(
            $counts['total_indication'],
            $counts['total_baseline'],
            $counts['with_physician_indication'],
            $counts['with_physician_baseline'],
            $counts['resus_indication'],
            $counts['resus_baseline'],
            $counts['cathlab_indication'],
            $counts['cathlab_baseline'],
            $counts['urgency_emergency_indication'],
            $counts['urgency_emergency_baseline'],
            $counts['infectious_indication'],
            $counts['infectious_baseline'],
            $counts['cpr_indication'],
            $counts['cpr_baseline'],
            $counts['ventilated_indication'],
            $counts['ventilated_baseline'],
            $counts['shock_indication'],
            $counts['shock_baseline'],
            $counts['pregnant_indication'],
            $counts['pregnant_baseline'],
            $counts['work_accident_indication'],
            $counts['work_accident_baseline'],
            $counts['night_daytime_indication'],
            $counts['night_daytime_baseline'],
            $counts['weekend_indication'],
            $counts['weekend_baseline'],
            $counts['age_80_plus_indication'],
            $counts['age_80_plus_baseline'],
            $counts['male_indication'],
            $counts['male_baseline'],
            $counts['female_indication'],
            $counts['female_baseline'],
            $this->toFloatOrNull($indicationRow['median_age_indication'] ?? null),
            $this->toFloatOrNull($scopeRow['median_age_baseline'] ?? null),
            $this->toFloatOrNull($indicationRow['median_transport_indication'] ?? null),
            $this->toFloatOrNull($scopeRow['median_transport_baseline'] ?? null),
            $counts['ground_transport_indication'],
            $counts['ground_transport_baseline'],
            $counts['air_transport_indication'],
            $counts['air_transport_baseline'],
            $counts['urgency_inpatient_indication'],
            $counts['urgency_inpatient_baseline'],
            $counts['urgency_outpatient_indication'],
            $counts['urgency_outpatient_baseline'],
        );
    }

    private function emptyRow(): IndicationDashboardMetricsRow
    {
        return new IndicationDashboardMetricsRow(
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            null, null, null, null,
            0, 0, 0, 0, 0, 0, 0, 0,
        );
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
