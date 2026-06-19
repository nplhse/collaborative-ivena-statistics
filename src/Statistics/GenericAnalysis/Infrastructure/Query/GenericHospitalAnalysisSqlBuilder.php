<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\HospitalAnalysisConstants;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @phpstan-type SqlBuildResult array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
 */
final readonly class GenericHospitalAnalysisSqlBuilder
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private GenericHospitalScopeSqlFilter $hospitalScopeSqlFilter,
        private GenericAnalysisScopeSqlFilter $allocationScopeSqlFilter,
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

        $isCompare = HospitalPopulationMode::Compare === $query->hospitalPopulationMode;

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
            if (null === $metric->sqlSelectExpression) {
                continue;
            }

            $selectParts[] = $isCompare && 'hospital_count' === $metricKey
                ? $this->compareHospitalCountExpression()
                : $metric->sqlSelectExpression;
        }

        [$conditions, $params] = $this->hospitalScopeSqlFilter->applyHospitalScope($query->scopeCriteria);
        $types = $this->hospitalScopeSqlFilter->parameterTypes($params);

        if (HospitalPopulationMode::Participating === $query->hospitalPopulationMode) {
            $conditions[] = 'h.is_participating = true';
        }

        if (!$query->includeNullBuckets) {
            $this->appendExcludeNullConditions($conditions, $params, $types, $primary);
            if ($series instanceof AnalysisDimension) {
                $this->appendExcludeNullConditions($conditions, $params, $types, $series);
            }
        }

        $fromParts = [
            'hospital h',
            'INNER JOIN state s ON s.id = h.state_id',
            'INNER JOIN dispatch_area da ON da.id = h.dispatch_area_id',
        ];

        if ($isCompare) {
            $fromParts[] = sprintf(
                "CROSS JOIN (VALUES ('%s'), ('%s')) AS g(population_group)",
                HospitalAnalysisConstants::POPULATION_GROUP_PARTICIPATING,
                HospitalAnalysisConstants::POPULATION_GROUP_NON_PARTICIPATING,
            );
        }

        [$allocSql, $allocParams, $allocTypes] = $this->buildAllocationSubquery($query);
        $fromParts[] = sprintf('LEFT JOIN (%s) alloc ON alloc.hospital_id = h.id', $allocSql);
        $params = array_merge($params, $allocParams);
        $types = array_merge($types, $allocTypes);

        $sql = sprintf(
            "SELECT\n    %s\nFROM %s\nWHERE %s\nGROUP BY %s\nORDER BY %s",
            implode(",\n    ", $selectParts),
            implode("\n", $fromParts),
            implode(' AND ', $conditions),
            implode(', ', $groupParts),
            implode(', ', $groupParts),
        );

        return [$sql, $params, $types];
    }

    /**
     * @return SqlBuildResult
     */
    private function buildAllocationSubquery(AnalysisQuery $query): array
    {
        [$conditions, $params] = $this->allocationScopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->allocationScopeSqlFilter->parameterTypes($params);

        $sql = sprintf(
            'SELECT hospital_id, COUNT(*)::int AS cnt FROM %s WHERE %s GROUP BY hospital_id',
            $this->allocationScopeSqlFilter->tableName(),
            implode(' AND ', $conditions),
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

    private function compareHospitalCountExpression(): string
    {
        return sprintf(
            <<<'SQL'
                COUNT(DISTINCT CASE
                    WHEN g.population_group = '%1$s' AND h.is_participating THEN h.id
                    WHEN g.population_group = '%2$s' AND NOT h.is_participating THEN h.id
                END)::INT AS hospital_count
                SQL,
            HospitalAnalysisConstants::POPULATION_GROUP_PARTICIPATING,
            HospitalAnalysisConstants::POPULATION_GROUP_NON_PARTICIPATING,
        );
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
        if (HospitalAnalysisConstants::POPULATION_GROUP_DIMENSION_KEY === $dimension->key) {
            return;
        }

        $expr = $dimension->selectExpression();
        $paramKey = 'exclude_null_'.$dimension->key;

        if (null !== $dimension->requiresNonNullSourceColumn) {
            $conditions[] = sprintf('%s IS NOT NULL', $dimension->requiresNonNullSourceColumn);
        } else {
            $conditions[] = sprintf('%s IS NOT NULL', $expr);
        }

        if ([] !== $dimension->nullBucketKeys) {
            $conditions[] = sprintf('%s NOT IN (:%s)', $expr, $paramKey);
            $params[$paramKey] = $dimension->nullBucketKeys;
            $types[$paramKey] = ArrayParameterType::STRING;
        }
    }
}
