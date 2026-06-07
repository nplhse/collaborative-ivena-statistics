<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
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

        $this->registerTransportMetrics();
        $this->registerBooleanRateMetrics();

        // TODO: min_*, max_*, p95_*, stddev_*, distinct_* aggregates.
        // TODO: ci95_percent (Wilson interval), ci95_mean (classic CI) — MetricComputationKind::InferentialStub.
    }

    private function registerTransportMetrics(): void
    {
        $column = 'transport_time_minutes';

        $this->register(new MetricDefinition(
            key: 'mean_transport_time',
            label: 'Mean transport time',
            metricType: MetricType::NumericAggregate,
            computationKind: MetricComputationKind::SqlAggregate,
            sqlSelectExpression: sprintf('AVG(%s)::DOUBLE PRECISION AS mean_transport_time', $column),
            sourceColumn: $column,
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
        );
        $this->registerPercentileMetric(
            key: 'p25_transport_time',
            label: 'P25 transport time',
            column: $column,
            percentile: 0.25,
            sortPriority: 32,
        );
        $this->registerPercentileMetric(
            key: 'p75_transport_time',
            label: 'P75 transport time',
            column: $column,
            percentile: 0.75,
            sortPriority: 33,
        );
        $this->registerPercentileMetric(
            key: 'p90_transport_time',
            label: 'P90 transport time',
            column: $column,
            percentile: 0.9,
            sortPriority: 34,
        );
    }

    private function registerPercentileMetric(
        string $key,
        string $label,
        string $column,
        float $percentile,
        int $sortPriority,
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
            sourceColumn: $column,
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
