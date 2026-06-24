<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Doctrine\DBAL\Connection;

/**
 * Executes generic analysis aggregations against allocation_stats_projection.
 */
final readonly class GenericAllocationAnalysisQuery
{
    public function __construct(
        private Connection $connection,
        private GenericAllocationAnalysisSqlBuilder $sqlBuilder,
        private MetricRegistry $metricRegistry,
    ) {
    }

    public function execute(AnalysisQuery $query): AnalysisResult
    {
        $metricKeys = $query->resolvedMetricKeys();
        $baseMetricKey = $query->dataSource->distributionBaseMetricKey();

        if ($this->hasEmptyHospitalScope($query)) {
            return new AnalysisResult(
                rows: [],
                grandTotal: 0,
                primaryDimensionKey: $query->primaryDimensionKey,
                metricKeys: $metricKeys,
                seriesDimensionKey: $query->seriesDimensionKey,
                includeNullBuckets: $query->includeNullBuckets,
                distributionBaseMetricKey: $baseMetricKey,
                dataSource: AnalysisDataSource::Allocations,
            );
        }

        [$sql, $params, $types] = $this->sqlBuilder->build($query);

        $raw = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        $sqlMetricKeys = $this->sqlMetricKeys($query);
        $rows = [];
        $grandTotal = 0;

        foreach ($raw as $row) {
            $metrics = $this->mapMetrics($row, $sqlMetricKeys);
            $grandTotal += (int) ($metrics[$baseMetricKey] ?? 0);
            $rows[] = new AnalysisResultRow(
                bucket: $this->normalizeBucketValue($row['bucket']),
                metrics: $metrics,
                series: \array_key_exists('series', $row) ? $this->normalizeBucketValue($row['series']) : null,
            );
        }

        return new AnalysisResult(
            rows: $rows,
            grandTotal: $grandTotal,
            primaryDimensionKey: $query->primaryDimensionKey,
            metricKeys: $metricKeys,
            seriesDimensionKey: $query->seriesDimensionKey,
            includeNullBuckets: $query->includeNullBuckets,
            distributionBaseMetricKey: $baseMetricKey,
            dataSource: AnalysisDataSource::Allocations,
        );
    }

    /**
     * @return list<string>
     */
    private function sqlMetricKeys(AnalysisQuery $query): array
    {
        $keys = [];
        foreach ($query->resolvedMetricKeys() as $metricKey) {
            $metric = $this->metricRegistry->get($metricKey);
            if (MetricComputationKind::SqlAggregate === $metric->computationKind) {
                $keys[] = $metricKey;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $sqlMetricKeys
     *
     * @return array<string, int|float|null>
     */
    private function mapMetrics(array $row, array $sqlMetricKeys): array
    {
        $metrics = [];
        foreach ($sqlMetricKeys as $key) {
            if (!\array_key_exists($key, $row)) {
                continue;
            }
            $metrics[$key] = $this->normalizeMetricValue($row[$key]);
        }

        if (!isset($metrics['count'])) {
            $metrics['count'] = 0;
        }

        return $metrics;
    }

    private function normalizeMetricValue(mixed $value): int|float|null
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function hasEmptyHospitalScope(AnalysisQuery $query): bool
    {
        $hospitalIds = $query->scopeCriteria->hospitalIds;

        return \is_array($hospitalIds) && [] === $hospitalIds;
    }

    private function normalizeBucketValue(mixed $value): int|string|float|null
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value) && !\is_string($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return (string) $value;
    }
}
