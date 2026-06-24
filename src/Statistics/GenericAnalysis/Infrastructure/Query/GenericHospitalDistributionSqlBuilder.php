<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDistributionValueSource;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\HospitalAnalysisConstants;
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
        $series = null !== $query->seriesDimensionKey
            ? $this->dimensionRegistry->get($query->seriesDimensionKey)
            : null;

        $isCompare = HospitalPopulationMode::Compare === $query->hospitalPopulationMode;

        $bucketExpr = $primary->selectExpression();
        $seriesExpr = $series?->selectExpression();
        $valueExpr = match ($valueSource) {
            ExplorerDistributionValueSource::HospitalBeds => 'h.beds::DOUBLE PRECISION',
            ExplorerDistributionValueSource::AllocationsPerHospital => 'COALESCE(alloc.cnt, 0)::DOUBLE PRECISION',
            ExplorerDistributionValueSource::HospitalMedianTransportTime => 'alloc.median_transport::DOUBLE PRECISION',
            ExplorerDistributionValueSource::AllocationTransportTime => throw new \InvalidArgumentException('Allocation transport time distribution uses GenericAllocationDistributionSqlBuilder.'),
        };

        $selectParts = [
            sprintf('%s AS bucket', $bucketExpr),
        ];
        $orderParts = ['bucket'];

        if (null !== $seriesExpr) {
            $selectParts[] = sprintf('%s AS series', $seriesExpr);
            $orderParts[] = 'series';
        }

        $selectParts[] = sprintf('%s AS value', $valueExpr);

        [$conditions, $params] = $this->hospitalScopeSqlFilter->applyHospitalScope($query->scopeCriteria);
        $types = $this->hospitalScopeSqlFilter->parameterTypes($params);

        if (HospitalPopulationMode::Participating === $query->hospitalPopulationMode) {
            $conditions[] = 'h.is_participating = true';
        }

        if ($isCompare) {
            $conditions[] = sprintf(
                "((g.population_group = '%s' AND h.is_participating = true) OR (g.population_group = '%s' AND h.is_participating = false))",
                HospitalAnalysisConstants::POPULATION_GROUP_PARTICIPATING,
                HospitalAnalysisConstants::POPULATION_GROUP_NON_PARTICIPATING,
            );
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

        if (\in_array($valueSource, [
            ExplorerDistributionValueSource::AllocationsPerHospital,
            ExplorerDistributionValueSource::HospitalMedianTransportTime,
        ], true)) {
            [$allocSql, $allocParams, $allocTypes] = match ($valueSource) {
                ExplorerDistributionValueSource::AllocationsPerHospital => $this->buildAllocationCountSubquery($query),
                ExplorerDistributionValueSource::HospitalMedianTransportTime => $this->buildMedianTransportSubquery($query),
            };
            $fromParts[] = sprintf('LEFT JOIN (%s) alloc ON alloc.hospital_id = h.id', $allocSql);
            $params = array_merge($params, $allocParams);
            $types = array_merge($types, $allocTypes);
        }

        $sql = sprintf(
            "SELECT\n    %s\nFROM %s\nWHERE %s\nORDER BY %s",
            implode(",\n    ", $selectParts),
            implode("\n", $fromParts),
            implode(' AND ', $conditions),
            implode(', ', $orderParts),
        );

        return [$sql, $params, $types];
    }

    /**
     * @return SqlBuildResult
     */
    private function buildAllocationCountSubquery(AnalysisQuery $query): array
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
     * @return SqlBuildResult
     */
    private function buildMedianTransportSubquery(AnalysisQuery $query): array
    {
        [$conditions, $params] = $this->allocationScopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->allocationScopeSqlFilter->parameterTypes($params);

        $conditions[] = 'arrival_at IS NOT NULL';
        $conditions[] = 'created_at IS NOT NULL';

        $medianExpr = StatisticsTransportTimeSql::medianPreciseMinutes();
        $sql = sprintf(
            'SELECT hospital_id, %s AS median_transport FROM %s WHERE %s GROUP BY hospital_id',
            $medianExpr,
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
