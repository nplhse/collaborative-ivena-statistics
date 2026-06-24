<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;
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
    private const string ALLOCATION_ALIAS = 'a';
    private const string PRIMARY_UNPIVOT_ALIAS = 'i';
    private const string SERIES_UNPIVOT_ALIAS = 'j';

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
        $primaryIsUnpivot = ClinicalIndicatorDefinitions::isUnpivotDimension($primary->key);
        $seriesIsUnpivot = $series instanceof AnalysisDimension
            && ClinicalIndicatorDefinitions::isUnpivotDimension($series->key);
        $usesAllocationAlias = $primaryIsUnpivot || $seriesIsUnpivot;

        $bucketExpr = $primaryIsUnpivot
            ? self::PRIMARY_UNPIVOT_ALIAS.'.indicator_key'
            : ($usesAllocationAlias ? $this->aliasedSelectExpression($primary) : $primary->selectExpression());
        $seriesExpr = null;
        if ($series instanceof AnalysisDimension) {
            $seriesExpr = match (true) {
                $primaryIsUnpivot && $seriesIsUnpivot => self::SERIES_UNPIVOT_ALIAS.'.indicator_key',
                $seriesIsUnpivot => self::PRIMARY_UNPIVOT_ALIAS.'.indicator_key',
                $usesAllocationAlias => $this->aliasedSelectExpression($series),
                default => $series->selectExpression(),
            };
        }

        $selectParts = [
            sprintf('%s AS bucket', $bucketExpr),
        ];
        $groupParts = ['bucket'];

        if (null !== $seriesExpr) {
            $selectParts[] = sprintf('%s AS series', $seriesExpr);
            $groupParts[] = 'series';
        }

        foreach ($this->sqlAggregateMetricKeys($query) as $metricKey) {
            if ($usesAllocationAlias) {
                $selectParts[] = match ($metricKey) {
                    'count' => $this->unpivotCountExpression($primaryIsUnpivot, $seriesIsUnpivot),
                    'prevalence_rate' => $this->unpivotPrevalenceRateExpression($primaryIsUnpivot, $seriesIsUnpivot),
                    default => $this->metricRegistry->get($metricKey)->sqlSelectExpression
                        ?? throw new \LogicException(sprintf('Metric "%s" is not supported for clinical indicator dimensions.', $metricKey)),
                };
                continue;
            }

            $metric = $this->metricRegistry->get($metricKey);
            if (null !== $metric->sqlSelectExpression) {
                $selectParts[] = $metric->sqlSelectExpression;
            }
        }

        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );

        if ($usesAllocationAlias) {
            $conditions = $this->qualifyScopeConditions($conditions);
        }

        $types = $this->scopeSqlFilter->parameterTypes($params);

        if (!$query->includeNullBuckets) {
            $this->appendExcludeNullConditions($conditions, $params, $types, $primary, $usesAllocationAlias);
            if ($series instanceof AnalysisDimension) {
                $this->appendExcludeNullConditions($conditions, $params, $types, $series, $usesAllocationAlias);
            }
        }

        foreach ($query->filters as $filter) {
            [$filterSql, $filterParams, $filterTypes] = $this->buildFilter($filter, $usesAllocationAlias);
            $conditions[] = $filterSql;
            $params = array_merge($params, $filterParams);
            $types = array_merge($types, $filterTypes);
        }

        $fromClause = $this->buildFromClause($primary, $series, $primaryIsUnpivot, $seriesIsUnpivot);

        $sql = sprintf(
            "SELECT\n    %s\nFROM %s\nWHERE %s\nGROUP BY %s\nORDER BY %s",
            implode(",\n    ", $selectParts),
            $fromClause,
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

    private function unpivotCountExpression(bool $primaryIsUnpivot, bool $seriesIsUnpivot): string
    {
        return sprintf(
            'COUNT(*) FILTER (WHERE %s)::INT AS count',
            $this->unpivotFilterCondition($primaryIsUnpivot, $seriesIsUnpivot),
        );
    }

    private function unpivotPrevalenceRateExpression(bool $primaryIsUnpivot, bool $seriesIsUnpivot): string
    {
        return sprintf(
            '(COUNT(*) FILTER (WHERE %s)::DOUBLE PRECISION / NULLIF(COUNT(*), 0) * 100)::DOUBLE PRECISION AS prevalence_rate',
            $this->unpivotFilterCondition($primaryIsUnpivot, $seriesIsUnpivot),
        );
    }

    private function unpivotFilterCondition(bool $primaryIsUnpivot, bool $seriesIsUnpivot): string
    {
        if ($primaryIsUnpivot && $seriesIsUnpivot) {
            return sprintf(
                '(%s) AND (%s)',
                ClinicalIndicatorDefinitions::indicatorMatchCaseExpression(
                    self::ALLOCATION_ALIAS,
                    self::PRIMARY_UNPIVOT_ALIAS,
                ),
                ClinicalIndicatorDefinitions::indicatorMatchCaseExpression(
                    self::ALLOCATION_ALIAS,
                    self::SERIES_UNPIVOT_ALIAS,
                ),
            );
        }

        if ($primaryIsUnpivot) {
            return ClinicalIndicatorDefinitions::indicatorMatchCaseExpression(
                self::ALLOCATION_ALIAS,
                self::PRIMARY_UNPIVOT_ALIAS,
            );
        }

        return ClinicalIndicatorDefinitions::indicatorMatchCaseExpression(
            self::ALLOCATION_ALIAS,
            self::PRIMARY_UNPIVOT_ALIAS,
        );
    }

    private function buildFromClause(
        AnalysisDimension $primary,
        ?AnalysisDimension $series,
        bool $primaryIsUnpivot,
        bool $seriesIsUnpivot,
    ): string {
        if (!$primaryIsUnpivot && !$seriesIsUnpivot) {
            return $this->scopeSqlFilter->tableName();
        }

        $parts = [
            sprintf('%s %s', $this->scopeSqlFilter->tableName(), self::ALLOCATION_ALIAS),
        ];

        if ($primaryIsUnpivot) {
            $parts[] = 'CROSS JOIN '.ClinicalIndicatorDefinitions::crossJoinValuesSql(
                $primary->key,
                self::PRIMARY_UNPIVOT_ALIAS,
            );
        }

        if ($seriesIsUnpivot && $series instanceof AnalysisDimension) {
            $parts[] = 'CROSS JOIN '.ClinicalIndicatorDefinitions::crossJoinValuesSql(
                $series->key,
                $primaryIsUnpivot ? self::SERIES_UNPIVOT_ALIAS : self::PRIMARY_UNPIVOT_ALIAS,
            );
        }

        return implode("\n", $parts);
    }

    private function aliasedSelectExpression(AnalysisDimension $dimension): string
    {
        if (null === $dimension->sqlExpression) {
            return self::ALLOCATION_ALIAS.'.'.$dimension->column;
        }

        if ('' !== $dimension->column && str_contains($dimension->sqlExpression, $dimension->column)) {
            return str_replace($dimension->column, self::ALLOCATION_ALIAS.'.'.$dimension->column, $dimension->sqlExpression);
        }

        return $dimension->sqlExpression;
    }

    /**
     * @param list<string> $conditions
     *
     * @return list<string>
     */
    private function qualifyScopeConditions(array $conditions): array
    {
        $columns = [
            'created_at',
            'hospital_id',
            'hospital_location_code',
            'hospital_tier_code',
        ];

        $qualified = [];
        foreach ($conditions as $condition) {
            foreach ($columns as $column) {
                $condition = preg_replace(
                    '/\b'.preg_quote($column, '/').'\b/',
                    self::ALLOCATION_ALIAS.'.'.$column,
                    $condition,
                ) ?? $condition;
            }
            $qualified[] = $condition;
        }

        return $qualified;
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
        bool $usesAllocationAlias,
    ): void {
        if (ClinicalIndicatorDefinitions::isUnpivotDimension($dimension->key)) {
            return;
        }

        $expr = $usesAllocationAlias ? $this->aliasedSelectExpression($dimension) : $dimension->selectExpression();
        $conditions[] = sprintf('%s IS NOT NULL', $expr);

        if (null !== $dimension->requiresNonNullSourceColumn) {
            $sourceColumn = $usesAllocationAlias
                ? self::ALLOCATION_ALIAS.'.'.$dimension->requiresNonNullSourceColumn
                : $dimension->requiresNonNullSourceColumn;
            $conditions[] = sprintf('%s IS NOT NULL', $sourceColumn);
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
    private function buildFilter(AnalysisFilter $filter, bool $usesAllocationAlias): array
    {
        if (!$this->dimensionRegistry->has($filter->dimensionKey)) {
            throw UnknownAnalysisDimensionException::forKey($filter->dimensionKey);
        }

        $dimension = $this->dimensionRegistry->get($filter->dimensionKey);
        $expr = $usesAllocationAlias ? $this->aliasedSelectExpression($dimension) : $dimension->selectExpression();
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
