<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;
use App\Statistics\GenericAnalysis\Application\DTO\MetricCompatibilityResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Exception\IncompatibleAnalysisMetricException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisMetricException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final readonly class MetricCompatibilityChecker
{
    /** @var list<string> */
    private const array PROJECTION_SOURCE_COLUMNS = [
        'transport_time_minutes',
        'requires_resus',
        'is_cpr',
        'is_shock',
        'is_ventilated',
        'requires_cathlab',
        'is_pregnant',
        'is_work_accident',
        'is_with_physician',
    ];

    public function __construct(
        private MetricRegistry $metricRegistry,
        private DimensionRegistry $dimensionRegistry,
    ) {
    }

    public function check(
        AnalysisQuery $query,
        AnalysisDimension $_primary,
        ?AnalysisDimension $_series,
        MetricDefinition $metric,
    ): MetricCompatibilityResult {
        if (MetricComputationKind::Relative !== $metric->computationKind
            && $metric->dataSource !== $query->dataSource) {
            return MetricCompatibilityResult::denied('Metric is not available for this data source.');
        }

        if (MetricComputationKind::InferentialStub === $metric->computationKind) {
            return MetricCompatibilityResult::denied('Metric is not implemented yet.');
        }

        if ('prevalence_rate' === $metric->key) {
            if (!ClinicalIndicatorDefinitions::isUnpivotDimension($query->primaryDimensionKey)
                && !ClinicalIndicatorDefinitions::isUnpivotDimension((string) $query->seriesDimensionKey)) {
                return MetricCompatibilityResult::denied('Prevalence rate requires a clinical indicator dimension.');
            }

            return MetricCompatibilityResult::allowed();
        }

        if ($metric->key === $query->dataSource->distributionBaseMetricKey()) {
            return MetricCompatibilityResult::allowed();
        }

        if (null !== $metric->sourceColumn
            && !\in_array($metric->sourceColumn, self::PROJECTION_SOURCE_COLUMNS, true)) {
            return MetricCompatibilityResult::denied('Source field not available.');
        }

        if ($metric->requiresSeriesDimension && null === $query->seriesDimensionKey) {
            return MetricCompatibilityResult::denied('Requires a series dimension.');
        }

        $resolvedKeys = $query->resolvedMetricKeys();
        foreach ($metric->requiredBaseMetricKeys as $baseKey) {
            $effectiveKey = $this->resolveBaseMetricKey($baseKey, $query->dataSource);
            if (!\in_array($effectiveKey, $resolvedKeys, true)) {
                return MetricCompatibilityResult::denied(sprintf('Requires base metric "%s".', $effectiveKey));
            }
        }

        return MetricCompatibilityResult::allowed();
    }

    /**
     * @return list<MetricDefinition>
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function resolveAndValidate(AnalysisQuery $query): array
    {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        if ($primary->dataSource !== $query->dataSource) {
            throw UnknownAnalysisDimensionException::forKey($query->primaryDimensionKey);
        }

        $series = null;
        if (null !== $query->seriesDimensionKey) {
            $series = $this->dimensionRegistry->get($query->seriesDimensionKey);
            if ($series->dataSource !== $query->dataSource) {
                throw UnknownAnalysisDimensionException::forKey($query->seriesDimensionKey);
            }
        }

        $definitions = [];
        foreach ($query->resolvedMetricKeys() as $key) {
            if (!$this->metricRegistry->has($key)) {
                throw UnknownAnalysisMetricException::forKey($key);
            }

            $metric = $this->metricRegistry->get($key);
            $result = $this->check($query, $primary, $series, $metric);
            if (!$result->allowed) {
                throw IncompatibleAnalysisMetricException::forMetric($key, $result->reason);
            }

            $definitions[] = $metric;
        }

        return $definitions;
    }

    /**
     * @return list<array{metric: MetricDefinition, allowed: bool, reason: ?string}>
     */
    public function listAvailability(AnalysisQuery $query): array
    {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $series = null !== $query->seriesDimensionKey
            ? $this->dimensionRegistry->get($query->seriesDimensionKey)
            : null;

        $items = [];
        foreach ($this->metricRegistry->all() as $metric) {
            if ($metric->dataSource !== $query->dataSource
                && MetricComputationKind::Relative !== $metric->computationKind) {
                continue;
            }

            $result = $this->check($query, $primary, $series, $metric);
            $items[] = [
                'metric' => $metric,
                'allowed' => $result->allowed,
                'reason' => $result->reason,
            ];
        }

        return $items;
    }

    private function resolveBaseMetricKey(string $baseKey, AnalysisDataSource $dataSource): string
    {
        if ('count' === $baseKey) {
            return $dataSource->distributionBaseMetricKey();
        }

        return $baseKey;
    }
}
