<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @phpstan-type SqlBuildResult array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
 */
final readonly class GenericAllocationDistributionSqlBuilder
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
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
        $valueExpr = StatisticsTransportTimeSql::preciseMinutesExpression();

        $selectParts = [
            sprintf('%s AS bucket', $bucketExpr),
        ];
        $orderParts = ['bucket'];

        if (null !== $seriesExpr) {
            $selectParts[] = sprintf('%s AS series', $seriesExpr);
            $orderParts[] = 'series';
        }

        $selectParts[] = sprintf('%s AS value', $valueExpr);

        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->scopeSqlFilter->parameterTypes($params);

        $conditions[] = 'arrival_at IS NOT NULL';
        $conditions[] = 'created_at IS NOT NULL';

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

        $sql = sprintf(
            "SELECT\n    %s\nFROM %s\nWHERE %s\nORDER BY %s",
            implode(",\n    ", $selectParts),
            $this->scopeSqlFilter->tableName(),
            implode(' AND ', $conditions),
            implode(', ', $orderParts),
        );

        return [$sql, $params, $types];
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

        if ('age_group' === $filter->dimensionKey && \is_string($filter->value)) {
            $aggregateCondition = StatisticsAgeGroupFilter::sqlCondition('age', $filter->value);
            if (null !== $aggregateCondition) {
                return [$aggregateCondition, [], []];
            }
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
