<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
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
     * @return list<AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        if ($this->hasEmptyHospitalScope($query)) {
            return [];
        }

        $grain = $this->resolvedGrain($query);

        return match ($query->dimensionKey) {
            AnalysisDimensionKey::Time => $this->executeTimeSeries($query, $grain),
            AnalysisDimensionKey::Gender => $this->executeGender($query, $grain),
            AnalysisDimensionKey::Urgency => $this->executeUrgency($query, $grain),
        };
    }

    /**
     * @return list<AnalysisResultRow>
     */
    private function executeTimeSeries(AnalysisQuery $query, AnalysisDimensionGrain $grain): array
    {
        [$bucketColumn, $labelFormatter] = match ($grain) {
            AnalysisDimensionGrain::Year => ['created_year', $this->formatYearLabel(...)],
            default => ['created_month', $this->formatMonthLabel(...)],
        };

        return $this->fetchSingleDimension($query, $bucketColumn, $labelFormatter);
    }

    /**
     * @return list<AnalysisResultRow>
     */
    private function executeGender(AnalysisQuery $query, AnalysisDimensionGrain $grain): array
    {
        if (AnalysisDimensionGrain::Total === $grain) {
            return $this->fetchSingleDimension($query, 'gender_code', $this->formatGenderLabel(...));
        }

        return $this->fetchOverTime($query, $grain, 'gender_code', $this->formatGenderLabel(...));
    }

    /**
     * @return list<AnalysisResultRow>
     */
    private function executeUrgency(AnalysisQuery $query, AnalysisDimensionGrain $grain): array
    {
        if (AnalysisDimensionGrain::Total === $grain) {
            return $this->fetchSingleDimension($query, 'urgency_code', $this->formatUrgencyLabel(...));
        }

        return $this->fetchOverTime($query, $grain, 'urgency_code', $this->formatUrgencyLabel(...));
    }

    /**
     * @param callable(int|string): string $labelFormatter
     *
     * @return list<AnalysisResultRow>
     */
    private function fetchSingleDimension(AnalysisQuery $query, string $groupColumn, callable $labelFormatter): array
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
        $resultRows = [];

        foreach ($rows as $row) {
            $bucket = $row['bucket'];
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $bucketKey = \is_int($bucket) ? $bucket : (string) $bucket;
            $resultRows[] = new AnalysisResultRow(
                bucket: (string) $bucketKey,
                bucketLabel: $labelFormatter($bucketKey),
                seriesKey: null,
                seriesLabel: null,
                value: (int) $row['allocation_count'],
            );
        }

        return $resultRows;
    }

    /**
     * @param callable(int|string): string $seriesLabelFormatter
     *
     * @return list<AnalysisResultRow>
     */
    private function fetchOverTime(
        AnalysisQuery $query,
        AnalysisDimensionGrain $grain,
        string $seriesColumn,
        callable $seriesLabelFormatter,
    ): array {
        [$timeColumn, $timeLabelFormatter] = match ($grain) {
            AnalysisDimensionGrain::Year => ['created_year', $this->formatYearLabel(...)],
            default => ['created_month', $this->formatMonthLabel(...)],
        };

        [$conditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod(
            $query->scopeCriteria,
            $query->periodBounds,
        );
        $types = $this->scopeSqlFilter->parameterTypes($params);

        $table = $this->scopeSqlFilter->tableName();
        $sql = sprintf(
            'SELECT %s AS bucket, %s AS series, COUNT(*)::INT AS allocation_count FROM %s WHERE %s GROUP BY bucket, series ORDER BY bucket, series',
            $timeColumn,
            $seriesColumn,
            $table,
            implode(' AND ', $conditions),
        );

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $resultRows = [];

        foreach ($rows as $row) {
            $bucket = $row['bucket'];
            $series = $row['series'];
            if (null === $bucket || '' === $bucket || null === $series || '' === $series) {
                continue;
            }

            $bucketKey = \is_int($bucket) ? $bucket : (string) $bucket;
            $seriesKey = \is_int($series) ? $series : (string) $series;

            $resultRows[] = new AnalysisResultRow(
                bucket: (string) $bucketKey,
                bucketLabel: $timeLabelFormatter($bucketKey),
                seriesKey: (string) $seriesKey,
                seriesLabel: $seriesLabelFormatter($seriesKey),
                value: (int) $row['allocation_count'],
            );
        }

        return $resultRows;
    }

    private function resolvedGrain(AnalysisQuery $query): AnalysisDimensionGrain
    {
        $grain = $query->timeGrain ?? AnalysisDimensionGrain::Month;

        if (AnalysisDimensionKey::Time !== $query->dimensionKey && AnalysisDimensionGrain::Total !== $grain && !$grain->isTemporal()) {
            return AnalysisDimensionGrain::Total;
        }

        return $grain;
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
