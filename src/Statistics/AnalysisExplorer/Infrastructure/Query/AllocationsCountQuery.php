<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisDataPoint;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aggregates allocation counts from allocation_stats_projection by explorer dimension.
 *
 * Gender dimension maps to projection column gender_code (see DimensionRegistry key "gender").
 */
final readonly class AllocationsCountQuery
{
    public function __construct(
        private Connection $connection,
        private GenericAnalysisScopeSqlFilter $scopeSqlFilter,
        private TranslatorInterface $translator,
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

        return match ($query->dimensionKey) {
            AnalysisDimensionKey::Time => $this->executeTimeSeries($query),
            AnalysisDimensionKey::Gender => $this->executeCategorical($query, 'gender_code', $this->formatGenderLabel(...)),
            AnalysisDimensionKey::Urgency => $this->executeCategorical($query, 'urgency_code', $this->formatUrgencyLabel(...)),
        };
    }

    /**
     * @return list<AnalysisDataPoint>
     */
    private function executeTimeSeries(AnalysisQuery $query): array
    {
        $timeGrain = $query->timeGrain ?? AnalysisDimensionGrain::Month;

        [$bucketColumn, $labelFormatter] = match ($timeGrain) {
            AnalysisDimensionGrain::Month => ['created_month', $this->formatMonthLabel(...)],
            AnalysisDimensionGrain::Year => ['created_year', $this->formatYearLabel(...)],
        };

        return $this->fetchGrouped($query, $bucketColumn, $labelFormatter);
    }

    /**
     * @param callable(int|string): string $labelFormatter
     *
     * @return list<AnalysisDataPoint>
     */
    private function executeCategorical(AnalysisQuery $query, string $column, callable $labelFormatter): array
    {
        return $this->fetchGrouped($query, $column, $labelFormatter);
    }

    /**
     * @param callable(int|string): string $labelFormatter
     *
     * @return list<AnalysisDataPoint>
     */
    private function fetchGrouped(AnalysisQuery $query, string $groupColumn, callable $labelFormatter): array
    {
        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->scopeSqlFilter->parameterTypes($params);

        $table = $this->scopeSqlFilter->tableName();
        $sql = sprintf(
            'SELECT %s AS bucket, COUNT(*)::INT AS allocation_count FROM %s WHERE %s GROUP BY bucket ORDER BY bucket',
            $groupColumn,
            $table,
            implode(' AND ', $conditions),
        );

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $dataPoints = [];

        foreach ($rows as $row) {
            $bucket = $row['bucket'];
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $bucketKey = \is_int($bucket) ? $bucket : (string) $bucket;
            $dataPoints[] = new AnalysisDataPoint(
                bucket: (string) $bucketKey,
                label: $labelFormatter($bucketKey),
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

    private function formatMonthLabel(int|string $bucket): string
    {
        $bucket = (string) $bucket;
        $date = \DateTimeImmutable::createFromFormat('Y-m', $bucket);
        if (false === $date) {
            return $bucket;
        }

        return $date->format('M Y');
    }

    private function formatYearLabel(int|string $bucket): string
    {
        return (string) $bucket;
    }

    private function formatGenderLabel(int|string $code): string
    {
        $enum = AllocationStatsGenderProjectionCode::tryFrom((int) $code);
        if (!$enum instanceof AllocationStatsGenderProjectionCode) {
            return (string) $code;
        }

        return $this->translator->trans($enum->labelTranslationKey());
    }

    private function formatUrgencyLabel(int|string $code): string
    {
        $enum = AllocationStatsUrgencyProjectionCode::tryFrom((int) $code);
        if (!$enum instanceof AllocationStatsUrgencyProjectionCode) {
            return (string) $code;
        }

        return $this->translator->trans($enum->labelTranslationKey());
    }
}
