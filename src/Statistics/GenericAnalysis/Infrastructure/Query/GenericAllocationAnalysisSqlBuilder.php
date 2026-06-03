<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Builds parameterized SQL for generic allocation_stats_projection aggregations.
 *
 * @phpstan-type SqlBuildResult array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
 */
final readonly class GenericAllocationAnalysisSqlBuilder
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private GenericAnalysisScopeSqlFilter $scopeSqlFilter,
    ) {
    }

    /**
     * @return SqlBuildResult
     */
    public function build(AnalysisQuery $query): array
    {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $series = null !== $query->seriesDimensionKey
            ? $this->dimensionRegistry->get($query->seriesDimensionKey)
            : null;

        $bucketExpr = $primary->selectExpression();
        $seriesExpr = $series?->selectExpression();

        $selectParts = [
            sprintf('%s AS bucket', $bucketExpr),
        ];
        $groupParts = ['bucket'];

        if (null !== $seriesExpr) {
            $selectParts[] = sprintf('%s AS series', $seriesExpr);
            $groupParts[] = 'series';
        }

        foreach ($this->sqlAggregateMetricKeys($query) as $metricKey) {
            $metric = $this->metricRegistry->get($metricKey);
            if (null !== $metric->sqlSelectExpression) {
                $selectParts[] = $metric->sqlSelectExpression;
            }
        }

        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );

        $types = $this->scopeSqlFilter->parameterTypes($params);

        if (!$query->includeNullBuckets) {
            $this->appendExcludeNullConditions($conditions, $params, $types, $primary);
            if ($series instanceof AnalysisDimension) {
                $this->appendExcludeNullConditions($conditions, $params, $types, $series);
            }
        }

        foreach ($query->filters as $filter) {
            [$filterSql, $filterParams, $filterTypes] = $this->buildFilter($filter);
            $conditions[] = $filterSql;
            $params = array_merge($params, $filterParams);
            $types = array_merge($types, $filterTypes);
        }

        $table = $this->scopeSqlFilter->tableName();
        $sql = sprintf(
            "SELECT\n    %s\nFROM %s\nWHERE %s\nGROUP BY %s\nORDER BY %s",
            implode(",\n    ", $selectParts),
            $table,
            implode(' AND ', $conditions),
            implode(', ', $groupParts),
            implode(', ', $groupParts),
        );

        return [$sql, $params, $types];
    }

    /**
     * @return list<string>
     */
    private function sqlAggregateMetricKeys(AnalysisQuery $query): array
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
     * @param list<string>         $conditions
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function appendExcludeNullConditions(
        array &$conditions,
        array &$params,
        array &$types,
        AnalysisDimension $dimension,
    ): void {
        $expr = $dimension->selectExpression();
        $conditions[] = sprintf('%s IS NOT NULL', $expr);

        if (null !== $dimension->requiresNonNullSourceColumn) {
            $conditions[] = sprintf('%s IS NOT NULL', $dimension->requiresNonNullSourceColumn);
        }

        if ([] !== $dimension->nullBucketKeys) {
            $param = 'exclude_null_bucket_'.$dimension->key;
            $conditions[] = sprintf('%s NOT IN (:%s)', $expr, $param);
            $params[$param] = $dimension->nullBucketKeys;
            $types[$param] = ArrayParameterType::STRING;
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildFilter(AnalysisFilter $filter): array
    {
        if (!$this->dimensionRegistry->has($filter->dimensionKey)) {
            throw UnknownAnalysisDimensionException::forKey($filter->dimensionKey);
        }

        $dimension = $this->dimensionRegistry->get($filter->dimensionKey);
        $expr = $dimension->selectExpression();
        $paramBase = 'filter_'.(preg_replace('/[^a-z0-9_]/', '_', $filter->dimensionKey) ?? $filter->dimensionKey);

        return match ($filter->operator) {
            AnalysisFilterOperator::Equals => [
                sprintf('%s = :%s', $expr, $paramBase),
                [$paramBase => $filter->value],
                [],
            ],
            AnalysisFilterOperator::In => [
                sprintf('%s IN (:%s)', $expr, $paramBase),
                [$paramBase => \is_array($filter->value) ? $filter->value : [$filter->value]],
                [$paramBase => ArrayParameterType::INTEGER],
            ],
        };
    }
}
