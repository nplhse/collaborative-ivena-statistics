<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationCompare;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareAggregationResult;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareSideCounts;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

/**
 * Dual-indication metrics in a single scan on allocation_stats_projection.
 */
final readonly class IndicationCompareMetricsQuery
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    public function fetch(
        int $indicationIdA,
        int $indicationIdB,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): IndicationCompareAggregationResult {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return IndicationCompareAggregationResult::empty();
        }

        $predA = 'indication_normalized_id = :id_a';
        $predB = 'indication_normalized_id = :id_b';

        [$scopeWhere, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $where = sprintf('(%s) AND (%s OR %s)', $scopeWhere, $predA, $predB);
        $params['id_a'] = $indicationIdA;
        $params['id_b'] = $indicationIdB;

        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $shockFilter = $hasExtended ? 'is_shock = true' : 'false';
        $pregnantFilter = $hasExtended ? 'is_pregnant = true' : 'false';
        $workAccidentFilter = $hasExtended ? 'is_work_accident = true' : 'false';

        $countSelect = $this->dualCountSelectSql($predA, $predB, $shockFilter, $pregnantFilter, $workAccidentFilter);

        $sql = <<<SQL
SELECT
    {$countSelect},
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age)
        FILTER (WHERE age IS NOT NULL AND {$predA}) AS side_a_median_age,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age)
        FILTER (WHERE age IS NOT NULL AND {$predB}) AS side_b_median_age,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes)
        FILTER (WHERE {$predA}) AS side_a_median_transport,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes)
        FILTER (WHERE {$predB}) AS side_b_median_transport
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return IndicationCompareAggregationResult::empty();
        }

        return new IndicationCompareAggregationResult(
            $this->mapSideCounts($row, 'a'),
            $this->mapSideCounts($row, 'b'),
        );
    }

    private function dualCountSelectSql(
        string $predA,
        string $predB,
        string $shockFilter,
        string $pregnantFilter,
        string $workAccidentFilter,
    ): string {
        $nightCode = AllocationStatsDayTimeBucketProjectionCode::Night->value;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $otherCode = AllocationStatsGenderProjectionCode::Other->value;
        $emergencyCode = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatientCode = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatientCode = AllocationStatsUrgencyProjectionCode::Outpatient->value;
        $groundCode = AllocationStatsTransportTypeProjectionCode::Ground->value;
        $airCode = AllocationStatsTransportTypeProjectionCode::Air->value;

        $metrics = [
            ['total', 'true'],
            ['with_physician', 'is_with_physician = true'],
            ['resus', 'requires_resus = true'],
            ['cathlab', 'requires_cathlab = true'],
            ['cpr', 'is_cpr = true'],
            ['ventilated', 'is_ventilated = true'],
            ['shock', $shockFilter],
            ['pregnant', $pregnantFilter],
            ['work_accident', $workAccidentFilter],
            ['infectious', 'infection_id IS NOT NULL'],
            ['urgency_emergency', sprintf('urgency_code = %d', $emergencyCode)],
            ['urgency_inpatient', sprintf('urgency_code = %d', $inpatientCode)],
            ['urgency_outpatient', sprintf('urgency_code = %d', $outpatientCode)],
            ['night_daytime', sprintf('day_time_bucket_code = %d', $nightCode)],
            ['weekend', 'created_weekday IN (6, 7)'],
            ['age_80_plus', 'age >= 80'],
            ['male', sprintf('gender_code = %d', $maleCode)],
            ['female', sprintf('gender_code = %d', $femaleCode)],
            ['gender_other', sprintf('gender_code = %d', $otherCode)],
            ['ground_transport', sprintf('transport_type_code = %d', $groundCode)],
            ['air_transport', sprintf('transport_type_code = %d', $airCode)],
        ];

        $parts = [];
        foreach ($metrics as [$alias, $condition]) {
            $parts[] = sprintf(
                'COUNT(*) FILTER (WHERE %s AND %s)::int AS side_a_%s',
                $condition,
                $predA,
                $alias,
            );
            $parts[] = sprintf(
                'COUNT(*) FILTER (WHERE %s AND %s)::int AS side_b_%s',
                $condition,
                $predB,
                $alias,
            );
        }

        return implode(",\n    ", $parts);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapSideCounts(array $row, string $side): IndicationCompareSideCounts
    {
        $prefix = 'side_'.$side.'_';

        return new IndicationCompareSideCounts(
            (int) ($row[$prefix.'total'] ?? 0),
            (int) ($row[$prefix.'with_physician'] ?? 0),
            (int) ($row[$prefix.'resus'] ?? 0),
            (int) ($row[$prefix.'cathlab'] ?? 0),
            (int) ($row[$prefix.'cpr'] ?? 0),
            (int) ($row[$prefix.'ventilated'] ?? 0),
            (int) ($row[$prefix.'shock'] ?? 0),
            (int) ($row[$prefix.'pregnant'] ?? 0),
            (int) ($row[$prefix.'work_accident'] ?? 0),
            (int) ($row[$prefix.'infectious'] ?? 0),
            (int) ($row[$prefix.'urgency_emergency'] ?? 0),
            (int) ($row[$prefix.'urgency_inpatient'] ?? 0),
            (int) ($row[$prefix.'urgency_outpatient'] ?? 0),
            (int) ($row[$prefix.'night_daytime'] ?? 0),
            (int) ($row[$prefix.'weekend'] ?? 0),
            (int) ($row[$prefix.'age_80_plus'] ?? 0),
            (int) ($row[$prefix.'male'] ?? 0),
            (int) ($row[$prefix.'female'] ?? 0),
            (int) ($row[$prefix.'gender_other'] ?? 0),
            (int) ($row[$prefix.'ground_transport'] ?? 0),
            (int) ($row[$prefix.'air_transport'] ?? 0),
            $this->toFloatOrNull($row[$prefix.'median_age'] ?? null),
            $this->toFloatOrNull($row[$prefix.'median_transport'] ?? null),
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
