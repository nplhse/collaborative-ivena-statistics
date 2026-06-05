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

        $nightCode = AllocationStatsDayTimeBucketProjectionCode::Night->value;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $emergencyCode = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatientCode = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatientCode = AllocationStatsUrgencyProjectionCode::Outpatient->value;
        $groundCode = AllocationStatsTransportTypeProjectionCode::Ground->value;
        $airCode = AllocationStatsTransportTypeProjectionCode::Air->value;

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;

        $sql = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id)::int AS total_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id)::int AS total_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND is_with_physician = true)::int AS with_physician_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND is_with_physician = true)::int AS with_physician_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND requires_resus = true)::int AS resus_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND requires_resus = true)::int AS resus_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND requires_cathlab = true)::int AS cathlab_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND requires_cathlab = true)::int AS cathlab_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND urgency_code = {$emergencyCode})::int AS urgency_emergency_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND urgency_code = {$emergencyCode})::int AS urgency_emergency_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND infection_id IS NOT NULL)::int AS infectious_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND infection_id IS NOT NULL)::int AS infectious_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND is_cpr = true)::int AS cpr_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND is_cpr = true)::int AS cpr_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND is_ventilated = true)::int AS ventilated_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND is_ventilated = true)::int AS ventilated_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND {$shockFilter})::int AS shock_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND {$shockFilter})::int AS shock_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND {$pregnantFilter})::int AS pregnant_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND {$pregnantFilter})::int AS pregnant_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND {$workAccidentFilter})::int AS work_accident_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND {$workAccidentFilter})::int AS work_accident_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND day_time_bucket_code = {$nightCode})::int AS night_daytime_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND day_time_bucket_code = {$nightCode})::int AS night_daytime_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND created_weekday IN (6, 7))::int AS weekend_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND created_weekday IN (6, 7))::int AS weekend_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND age >= 80)::int AS age_80_plus_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND age >= 80)::int AS age_80_plus_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND gender_code = {$maleCode})::int AS male_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND gender_code = {$maleCode})::int AS male_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND gender_code = {$femaleCode})::int AS female_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND gender_code = {$femaleCode})::int AS female_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND transport_type_code = {$groundCode})::int AS ground_transport_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND transport_type_code = {$groundCode})::int AS ground_transport_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND transport_type_code = {$airCode})::int AS air_transport_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND transport_type_code = {$airCode})::int AS air_transport_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND urgency_code = {$inpatientCode})::int AS urgency_inpatient_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND urgency_code = {$inpatientCode})::int AS urgency_inpatient_baseline,
    COUNT(*) FILTER (WHERE indication_normalized_id = :indication_id AND urgency_code = {$outpatientCode})::int AS urgency_outpatient_indication,
    COUNT(*) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND urgency_code = {$outpatientCode})::int AS urgency_outpatient_baseline,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age) FILTER (WHERE indication_normalized_id = :indication_id AND age IS NOT NULL) AS median_age_indication,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id AND age IS NOT NULL) AS median_age_baseline,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes) FILTER (WHERE indication_normalized_id = :indication_id) AS median_transport_indication,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes) FILTER (WHERE indication_normalized_id IS DISTINCT FROM :indication_id) AS median_transport_baseline
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $fetched = $this->connection->fetchAssociative($sql, $params);
        if (false === $fetched) {
            return $this->emptyRow();
        }

        $row = $fetched;

        return new IndicationDashboardMetricsRow(
            (int) ($row['total_indication'] ?? 0),
            (int) ($row['total_baseline'] ?? 0),
            (int) ($row['with_physician_indication'] ?? 0),
            (int) ($row['with_physician_baseline'] ?? 0),
            (int) ($row['resus_indication'] ?? 0),
            (int) ($row['resus_baseline'] ?? 0),
            (int) ($row['cathlab_indication'] ?? 0),
            (int) ($row['cathlab_baseline'] ?? 0),
            (int) ($row['urgency_emergency_indication'] ?? 0),
            (int) ($row['urgency_emergency_baseline'] ?? 0),
            (int) ($row['infectious_indication'] ?? 0),
            (int) ($row['infectious_baseline'] ?? 0),
            (int) ($row['cpr_indication'] ?? 0),
            (int) ($row['cpr_baseline'] ?? 0),
            (int) ($row['ventilated_indication'] ?? 0),
            (int) ($row['ventilated_baseline'] ?? 0),
            (int) ($row['shock_indication'] ?? 0),
            (int) ($row['shock_baseline'] ?? 0),
            (int) ($row['pregnant_indication'] ?? 0),
            (int) ($row['pregnant_baseline'] ?? 0),
            (int) ($row['work_accident_indication'] ?? 0),
            (int) ($row['work_accident_baseline'] ?? 0),
            (int) ($row['night_daytime_indication'] ?? 0),
            (int) ($row['night_daytime_baseline'] ?? 0),
            (int) ($row['weekend_indication'] ?? 0),
            (int) ($row['weekend_baseline'] ?? 0),
            (int) ($row['age_80_plus_indication'] ?? 0),
            (int) ($row['age_80_plus_baseline'] ?? 0),
            (int) ($row['male_indication'] ?? 0),
            (int) ($row['male_baseline'] ?? 0),
            (int) ($row['female_indication'] ?? 0),
            (int) ($row['female_baseline'] ?? 0),
            $this->toFloatOrNull($row['median_age_indication'] ?? null),
            $this->toFloatOrNull($row['median_age_baseline'] ?? null),
            $this->toFloatOrNull($row['median_transport_indication'] ?? null),
            $this->toFloatOrNull($row['median_transport_baseline'] ?? null),
            (int) ($row['ground_transport_indication'] ?? 0),
            (int) ($row['ground_transport_baseline'] ?? 0),
            (int) ($row['air_transport_indication'] ?? 0),
            (int) ($row['air_transport_baseline'] ?? 0),
            (int) ($row['urgency_inpatient_indication'] ?? 0),
            (int) ($row['urgency_inpatient_baseline'] ?? 0),
            (int) ($row['urgency_outpatient_indication'] ?? 0),
            (int) ($row['urgency_outpatient_baseline'] ?? 0),
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
