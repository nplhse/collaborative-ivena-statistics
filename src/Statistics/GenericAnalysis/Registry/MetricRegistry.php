<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalMetricClass;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricSourceType;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricType;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisMetricException;

final class MetricRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $metrics = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function get(string $key): MetricDefinition
    {
        return $this->metrics[$key] ?? throw UnknownAnalysisMetricException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->metrics[$key]);
    }

    /**
     * @return list<MetricDefinition>
     */
    public function all(): array
    {
        return array_values($this->metrics);
    }

    /**
     * @return list<MetricDefinition>
     */
    public function forDataSource(AnalysisDataSource $dataSource): array
    {
        return array_values(array_filter(
            $this->metrics,
            static fn (MetricDefinition $metric): bool => $metric->dataSource === $dataSource,
        ));
    }

    private function register(MetricDefinition $metric): void
    {
        $this->metrics[$metric->key] = $metric;
    }

    private function registerDefaults(): void
    {
        $this->register(new MetricDefinition(
            key: 'count',
            label: 'Count',
            metricType: MetricType::Count,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COUNT(*)::INT AS count',
            description: 'Number of allocations in scope',
            isDefault: true,
            sortPriority: 10,
        ));

        // Age aggregate metrics (mean_age, median_age, …) are intentionally out of scope for MVP.

        $this->register(new MetricDefinition(
            key: 'percent_of_total',
            label: 'Percent of total',
            metricType: MetricType::Relative,
            computationKind: MetricComputationKind::Relative,
            description: 'Share of row count relative to grand total',
            requiredBaseMetricKeys: ['count'],
            supportsRelativeMode: true,
            defaultFormat: MetricFormat::Percent,
            defaultPrecision: 2,
            sortPriority: 20,
        ));

        $this->register(new MetricDefinition(
            key: 'percent_of_bucket',
            label: 'Percent within bucket',
            metricType: MetricType::Relative,
            computationKind: MetricComputationKind::Relative,
            description: 'Share of row count within the primary bucket (requires series dimension)',
            requiredBaseMetricKeys: ['count'],
            requiresSeriesDimension: true,
            supportsRelativeMode: true,
            defaultFormat: MetricFormat::Percent,
            defaultPrecision: 2,
            sortPriority: 21,
        ));

        $this->register(new MetricDefinition(
            key: 'percent_of_row',
            label: 'Percent of row',
            metricType: MetricType::Relative,
            computationKind: MetricComputationKind::Relative,
            description: 'Share of row count within the pivot row total (requires series dimension)',
            requiredBaseMetricKeys: ['count'],
            requiresSeriesDimension: true,
            supportsRelativeMode: true,
            defaultFormat: MetricFormat::Percent,
            defaultPrecision: 2,
            sortPriority: 22,
        ));

        $this->registerTransportMetrics();
        $this->registerBooleanRateMetrics();
        $this->registerHospitalMetrics();

        // TODO: min_*, max_*, p95_*, stddev_*, distinct_* aggregates.
        // TODO: ci95_percent (Wilson interval), ci95_mean (classic CI) — MetricComputationKind::InferentialStub.
    }

    private function registerTransportMetrics(): void
    {
        $column = StatisticsTransportTimeSql::preciseMinutesExpression();

        $this->register(new MetricDefinition(
            key: 'mean_transport_time',
            label: 'Mean transport time',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: sprintf('AVG(%s)::DOUBLE PRECISION AS mean_transport_time', $column),
            sourceColumn: 'transport_time_minutes',
            requiredSourceType: MetricSourceType::Numeric,
            defaultFormat: MetricFormat::Minutes,
            defaultPrecision: 0,
            sortPriority: 30,
        ));

        $this->registerPercentileMetric(
            key: 'median_transport_time',
            label: 'Median transport time',
            column: $column,
            percentile: 0.5,
            sortPriority: 31,
            sourceColumn: 'transport_time_minutes',
        );
        $this->registerPercentileMetric(
            key: 'p25_transport_time',
            label: 'P25 transport time',
            column: $column,
            percentile: 0.25,
            sortPriority: 32,
            sourceColumn: 'transport_time_minutes',
        );
        $this->registerPercentileMetric(
            key: 'p75_transport_time',
            label: 'P75 transport time',
            column: $column,
            percentile: 0.75,
            sortPriority: 33,
            sourceColumn: 'transport_time_minutes',
        );
        $this->registerPercentileMetric(
            key: 'p90_transport_time',
            label: 'P90 transport time',
            column: $column,
            percentile: 0.9,
            sortPriority: 34,
            sourceColumn: 'transport_time_minutes',
        );
    }

    private function registerPercentileMetric(
        string $key,
        string $label,
        string $column,
        float $percentile,
        int $sortPriority,
        ?string $sourceColumn = null,
    ): void {
        $this->register(new MetricDefinition(
            key: $key,
            label: $label,
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: sprintf(
                '(PERCENTILE_CONT(%s) WITHIN GROUP (ORDER BY %s))::DOUBLE PRECISION AS %s',
                $percentile,
                $column,
                $key,
            ),
            sourceColumn: $sourceColumn ?? $column,
            requiredSourceType: MetricSourceType::Numeric,
            defaultFormat: MetricFormat::Minutes,
            defaultPrecision: 0,
            sortPriority: $sortPriority,
        ));
    }

    private function registerBooleanRateMetrics(): void
    {
        $this->registerBooleanRateMetric('resus_rate', 'Resus rate', 'requires_resus', 40);
        $this->registerBooleanRateMetric('cpr_rate', 'CPR rate', 'is_cpr', 41);
        $this->registerBooleanRateMetric('shock_rate', 'Shock rate', 'is_shock', 42);
        $this->registerBooleanRateMetric('ventilation_rate', 'Ventilation rate', 'is_ventilated', 43);
        $this->registerBooleanRateMetric('cathlab_rate', 'Cath lab rate', 'requires_cathlab', 44);
        $this->registerBooleanRateMetric('pregnancy_rate', 'Pregnancy rate', 'is_pregnant', 45);
        $this->registerBooleanRateMetric('work_accident_rate', 'Work accident rate', 'is_work_accident', 46);
        $this->registerBooleanRateMetric('with_physician_rate', 'Physician accompaniment rate', 'is_with_physician', 47);
    }

    private function registerHospitalMetrics(): void
    {
        $source = AnalysisDataSource::Hospitals;
        $structural = HospitalMetricClass::Structural;
        $allocationDerived = HospitalMetricClass::AllocationDerived;

        $this->register(new MetricDefinition(
            key: 'hospital_count',
            label: 'Hospital count',
            metricType: MetricType::Count,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COUNT(DISTINCT h.id)::INT AS hospital_count',
            description: 'Number of hospitals in scope',
            isDefault: true,
            sortPriority: 10,
            dataSource: $source,
            hospitalMetricClass: $structural,
        ));

        $this->register(new MetricDefinition(
            key: 'sum_beds',
            label: 'Total beds',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COALESCE(SUM(h.beds), 0)::INT AS sum_beds',
            defaultFormat: MetricFormat::Integer,
            sortPriority: 11,
            dataSource: $source,
            hospitalMetricClass: $structural,
        ));

        $this->register(new MetricDefinition(
            key: 'avg_beds',
            label: 'Average beds',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'AVG(h.beds)::DOUBLE PRECISION AS avg_beds',
            defaultFormat: MetricFormat::Decimal,
            defaultPrecision: 1,
            sortPriority: 12,
            dataSource: $source,
            hospitalMetricClass: $structural,
        ));

        $this->register(new MetricDefinition(
            key: 'min_beds',
            label: 'Minimum beds',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'MIN(h.beds)::INT AS min_beds',
            sortPriority: 13,
            dataSource: $source,
            hospitalMetricClass: $structural,
        ));

        $this->register(new MetricDefinition(
            key: 'max_beds',
            label: 'Maximum beds',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'MAX(h.beds)::INT AS max_beds',
            sortPriority: 14,
            dataSource: $source,
            hospitalMetricClass: $structural,
        ));

        $this->register(new MetricDefinition(
            key: 'total_allocations',
            label: 'Total allocations',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COALESCE(SUM(alloc.cnt), 0)::INT AS total_allocations',
            defaultFormat: MetricFormat::Integer,
            sortPriority: 20,
            dataSource: $source,
            hospitalMetricClass: $allocationDerived,
        ));

        $this->register(new MetricDefinition(
            key: 'avg_allocations_per_hospital',
            label: 'Average allocations per hospital',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: '(COALESCE(SUM(alloc.cnt), 0)::DOUBLE PRECISION / NULLIF(COUNT(DISTINCT h.id), 0)) AS avg_allocations_per_hospital',
            defaultFormat: MetricFormat::Decimal,
            defaultPrecision: 1,
            sortPriority: 21,
            dataSource: $source,
            hospitalMetricClass: $allocationDerived,
        ));

        $this->register(new MetricDefinition(
            key: 'min_allocations',
            label: 'Minimum allocations',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COALESCE(MIN(alloc.cnt), 0)::INT AS min_allocations',
            sortPriority: 22,
            dataSource: $source,
            hospitalMetricClass: $allocationDerived,
        ));

        $this->register(new MetricDefinition(
            key: 'max_allocations',
            label: 'Maximum allocations',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: 'COALESCE(MAX(alloc.cnt), 0)::INT AS max_allocations',
            sortPriority: 23,
            dataSource: $source,
            hospitalMetricClass: $allocationDerived,
        ));
    }

    private function registerBooleanRateMetric(
        string $key,
        string $label,
        string $column,
        int $sortPriority,
    ): void {
        $this->register(new MetricDefinition(
            key: $key,
            label: $label,
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: sprintf(
                '(COUNT(*) FILTER (WHERE %s IS TRUE)::DOUBLE PRECISION / NULLIF(COUNT(*), 0) * 100)::DOUBLE PRECISION AS %s',
                $column,
                $key,
            ),
            sourceColumn: $column,
            requiredSourceType: MetricSourceType::Boolean,
            defaultFormat: MetricFormat::Percent,
            defaultPrecision: 1,
            sortPriority: $sortPriority,
        ));
    }
}
