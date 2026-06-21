<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisDataPoint;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use Doctrine\DBAL\Connection;

final readonly class AllocationsTimeSeriesCountQuery
{
    public function __construct(
        private Connection $connection,
        private GenericAnalysisScopeSqlFilter $scopeSqlFilter,
    ) {
    }

    /**
     * @return list<AnalysisDataPoint>
     */
    public function execute(AnalysisQuery $query): array
    {
        if ($this->hasEmptyHospitalScope($query)) {
            return [];
        }

        [$bucketColumn, $labelFormatter] = match ($query->dimensionGrain) {
            AnalysisDimensionGrain::Month => ['created_month', $this->formatMonthLabel(...)],
            AnalysisDimensionGrain::Year => ['created_year', $this->formatYearLabel(...)],
        };

        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->scopeSqlFilter->parameterTypes($params);

        $table = $this->scopeSqlFilter->tableName();
        $sql = sprintf(
            'SELECT %s AS bucket, COUNT(*)::INT AS allocation_count FROM %s WHERE %s GROUP BY bucket ORDER BY bucket',
            $bucketColumn,
            $table,
            implode(' AND ', $conditions),
        );

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $dataPoints = [];

        foreach ($rows as $row) {
            $bucket = (string) $row['bucket'];
            $dataPoints[] = new AnalysisDataPoint(
                bucket: $bucket,
                label: $labelFormatter($bucket),
                value: (int) $row['allocation_count'],
            );
        }

        return $dataPoints;
    }

    private function hasEmptyHospitalScope(AnalysisQuery $query): bool
    {
        $hospitalIds = $query->scopeCriteria->hospitalIds;

        return \is_array($hospitalIds) && [] === $hospitalIds;
    }

    private function formatMonthLabel(string $bucket): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m', $bucket);
        if (false === $date) {
            return $bucket;
        }

        return $date->format('M Y');
    }

    private function formatYearLabel(string $bucket): string
    {
        return $bucket;
    }
}
