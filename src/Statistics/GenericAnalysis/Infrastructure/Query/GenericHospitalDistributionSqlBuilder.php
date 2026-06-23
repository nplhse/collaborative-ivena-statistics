<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDistributionValueSource;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @phpstan-type SqlBuildResult array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
 */
final readonly class GenericHospitalDistributionSqlBuilder
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private GenericHospitalScopeSqlFilter $hospitalScopeSqlFilter,
        private GenericAnalysisScopeSqlFilter $allocationScopeSqlFilter,
    ) {
    }

    /**
     * @return SqlBuildResult
     */
    public function build(AnalysisQuery $query, ExplorerDistributionValueSource $valueSource): array
    {
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $bucketExpr = $primary->selectExpression();
        $valueExpr = match ($valueSource) {
            ExplorerDistributionValueSource::HospitalBeds => 'h.beds::DOUBLE PRECISION',
            ExplorerDistributionValueSource::AllocationsPerHospital => 'COALESCE(alloc.cnt, 0)::DOUBLE PRECISION',
        };

        [$conditions, $params] = $this->hospitalScopeSqlFilter->applyHospitalScope($query->scopeCriteria);
        $types = $this->hospitalScopeSqlFilter->parameterTypes($params);

        if (HospitalPopulationMode::Participating === $query->hospitalPopulationMode) {
            $conditions[] = 'h.is_participating = true';
        }

        if (!$query->includeNullBuckets) {
            $this->appendExcludeNullConditions($conditions, $params, $types, $primary);
        }

        $fromParts = [
            'hospital h',
            'INNER JOIN state s ON s.id = h.state_id',
            'INNER JOIN dispatch_area da ON da.id = h.dispatch_area_id',
        ];

        if (ExplorerDistributionValueSource::AllocationsPerHospital === $valueSource) {
            [$allocSql, $allocParams, $allocTypes] = $this->buildAllocationSubquery($query);
            $fromParts[] = sprintf('LEFT JOIN (%s) alloc ON alloc.hospital_id = h.id', $allocSql);
            $params = array_merge($params, $allocParams);
            $types = array_merge($types, $allocTypes);
        }

        $sql = sprintf(
            "SELECT\n    %s AS bucket,\n    %s AS value\nFROM %s\nWHERE %s\nORDER BY bucket",
            $bucketExpr,
            $valueExpr,
            implode("\n", $fromParts),
            implode(' AND ', $conditions),
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
