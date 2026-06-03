<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
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

        // TODO: mean_age, median_age — register when age_group (or similar) dimension exists.

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

        // TODO: median_transport_time, p90_transport_time — register when transport time is in domain models.
        // TODO: min_*, max_*, p25_*, p75_*, p95_*, stddev_*, distinct_* aggregates.
        // TODO: ci95_percent (Wilson interval), ci95_mean (classic CI) — MetricComputationKind::InferentialStub.
    }
}
