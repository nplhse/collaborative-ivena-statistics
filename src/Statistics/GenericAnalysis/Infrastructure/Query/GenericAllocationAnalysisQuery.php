<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use Doctrine\DBAL\Connection;

/**
 * Executes generic analysis aggregations against allocation_stats_projection.
 */
final readonly class GenericAllocationAnalysisQuery
{
    public function __construct(
        private Connection $connection,
        private GenericAllocationAnalysisSqlBuilder $sqlBuilder,
    ) {
    }

    public function execute(AnalysisQuery $query): AnalysisResult
    {
        if ($this->hasEmptyHospitalScope($query)) {
            return new AnalysisResult(
                rows: [],
                grandTotal: 0,
                primaryDimensionKey: $query->primaryDimensionKey,
                seriesDimensionKey: $query->seriesDimensionKey,
                includeNullBuckets: $query->includeNullBuckets,
            );
        }

        [$sql, $params, $types] = $this->sqlBuilder->build($query);

        /** @var list<array{bucket: mixed, value: int|string, series?: mixed}> $raw */
        $raw = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        $rows = [];
        $grandTotal = 0;

        foreach ($raw as $row) {
            $value = (int) $row['value'];
            $grandTotal += $value;
            $rows[] = new AnalysisResultRow(
                bucket: $this->normalizeBucketValue($row['bucket']),
                value: $value,
                series: \array_key_exists('series', $row) ? $this->normalizeBucketValue($row['series']) : null,
            );
        }

        return new AnalysisResult(
            rows: $rows,
            grandTotal: $grandTotal,
            primaryDimensionKey: $query->primaryDimensionKey,
            seriesDimensionKey: $query->seriesDimensionKey,
            includeNullBuckets: $query->includeNullBuckets,
        );
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
