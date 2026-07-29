<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogPrivacyPolicy;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Aggregates visible allocation coverage for a catalog reference object.
 */
final readonly class CatalogCoverageQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function forDimension(CatalogDimensionKey $dimension, int $entityId): CatalogCoverage
    {
        try {
            $summary = $this->fetchSummary($dimension, $entityId);
            $total = $this->fetchTotalAllocations();
            $years = $this->fetchYears($dimension, $entityId);
        } catch (Exception) {
            return CatalogCoverage::empty();
        }

        return $this->buildCoverage($summary, $total, $years);
    }

    /**
     * Aggregates coverage across member indications (e.g. IndicationGroup).
     *
     * @param list<int> $indicationIds
     */
    public function forIndicationIds(array $indicationIds): CatalogCoverage
    {
        if ([] === $indicationIds) {
            return CatalogCoverage::empty();
        }

        try {
            $summary = $this->fetchSummaryForIndicationIds($indicationIds);
            $total = $this->fetchTotalAllocations();
            $years = $this->fetchYearsForIndicationIds($indicationIds);
        } catch (Exception) {
            return CatalogCoverage::empty();
        }

        return $this->buildCoverage($summary, $total, $years);
    }

    /**
     * Hospital coverage: allocation volumes (totals, yearly counts, share) require
     * hospital View permission. Without it, only period and year presence remain.
     */
    public function forHospital(int $hospitalId, bool $revealSensitiveMetrics): CatalogCoverage
    {
        try {
            $summary = $this->fetchSummary(CatalogDimensionKey::Hospital, $hospitalId);
            $total = $this->fetchTotalAllocations();
            $years = $this->fetchYears(CatalogDimensionKey::Hospital, $hospitalId);
        } catch (Exception) {
            return CatalogCoverage::empty();
        }

        $allocationCount = (int) $summary['allocation_count'];
        if ($allocationCount <= 0) {
            return CatalogCoverage::empty();
        }

        if (!$revealSensitiveMetrics) {
            // Keep year presence for the heatmap, but drop all volume from the DTO.
            $years = array_map(
                static fn (array $row): array => [
                    'year' => $row['year'],
                    'count' => $row['count'] > 0 ? 1 : 0,
                ],
                $years,
            );

            return new CatalogCoverage(
                allocationCount: 0,
                totalAllocationCount: 0,
                hospitalCount: 0,
                dispatchAreaCount: 0,
                stateCount: 0,
                firstAt: $this->parseDateTime($summary['first_at']),
                lastAt: $this->parseDateTime($summary['last_at']),
                years: $years,
                suppressed: false,
                revealSensitiveMetrics: false,
            );
        }

        return new CatalogCoverage(
            allocationCount: $allocationCount,
            totalAllocationCount: $total,
            hospitalCount: 1,
            dispatchAreaCount: (int) $summary['dispatch_area_count'],
            stateCount: (int) $summary['state_count'],
            firstAt: $this->parseDateTime($summary['first_at']),
            lastAt: $this->parseDateTime($summary['last_at']),
            years: $years,
            suppressed: false,
            revealSensitiveMetrics: true,
        );
    }

    /**
     * @param array{
     *     allocation_count: int|string|null,
     *     hospital_count: int|string|null,
     *     dispatch_area_count: int|string|null,
     *     state_count: int|string|null,
     *     first_at: string|\DateTimeInterface|null,
     *     last_at: string|\DateTimeInterface|null
     * } $summary
     * @param list<array{year: int, count: int}> $years
     */
    private function buildCoverage(array $summary, int $total, array $years): CatalogCoverage
    {
        $allocationCount = (int) $summary['allocation_count'];
        $suppressed = $allocationCount > 0 && $allocationCount < CatalogPrivacyPolicy::MIN_ALLOCATIONS;

        $firstAt = $this->parseDateTime($summary['first_at']);
        $lastAt = $this->parseDateTime($summary['last_at']);

        if ($suppressed) {
            return new CatalogCoverage(
                allocationCount: $allocationCount,
                totalAllocationCount: $total,
                hospitalCount: 0,
                dispatchAreaCount: 0,
                stateCount: 0,
                firstAt: null,
                lastAt: null,
                years: [],
                suppressed: true,
            );
        }

        return new CatalogCoverage(
            allocationCount: $allocationCount,
            totalAllocationCount: $total,
            hospitalCount: (int) $summary['hospital_count'],
            dispatchAreaCount: (int) $summary['dispatch_area_count'],
            stateCount: (int) $summary['state_count'],
            firstAt: $firstAt,
            lastAt: $lastAt,
            years: $years,
            suppressed: false,
        );
    }

    /**
     * @return array{
     *     allocation_count: int|string|null,
     *     hospital_count: int|string|null,
     *     dispatch_area_count: int|string|null,
     *     state_count: int|string|null,
     *     first_at: string|\DateTimeInterface|null,
     *     last_at: string|\DateTimeInterface|null
     * }
     */
    private function fetchSummary(CatalogDimensionKey $dimension, int $entityId): array
    {
        $column = $dimension->projectionColumn();
        $sql = <<<SQL
SELECT
    COUNT(*)::int AS allocation_count,
    COUNT(DISTINCT hospital_id)::int AS hospital_count,
    COUNT(DISTINCT dispatch_area_id)::int AS dispatch_area_count,
    COUNT(DISTINCT state_id)::int AS state_count,
    MIN(created_at) AS first_at,
    MAX(created_at) AS last_at
FROM allocation_stats_projection
WHERE {$column} = :entityId
SQL;

        /** @var array{
         *     allocation_count: int|string|null,
         *     hospital_count: int|string|null,
         *     dispatch_area_count: int|string|null,
         *     state_count: int|string|null,
         *     first_at: string|\DateTimeInterface|null,
         *     last_at: string|\DateTimeInterface|null
         * }|false $row
         */
        $row = $this->connection->fetchAssociative($sql, ['entityId' => $entityId]);

        if (false === $row) {
            return [
                'allocation_count' => 0,
                'hospital_count' => 0,
                'dispatch_area_count' => 0,
                'state_count' => 0,
                'first_at' => null,
                'last_at' => null,
            ];
        }

        return $row;
    }

    /**
     * @param list<int> $indicationIds
     *
     * @return array{
     *     allocation_count: int|string|null,
     *     hospital_count: int|string|null,
     *     dispatch_area_count: int|string|null,
     *     state_count: int|string|null,
     *     first_at: string|\DateTimeInterface|null,
     *     last_at: string|\DateTimeInterface|null
     * }
     */
    private function fetchSummaryForIndicationIds(array $indicationIds): array
    {
        $sql = <<<'SQL'
SELECT
    COUNT(*)::int AS allocation_count,
    COUNT(DISTINCT hospital_id)::int AS hospital_count,
    COUNT(DISTINCT dispatch_area_id)::int AS dispatch_area_count,
    COUNT(DISTINCT state_id)::int AS state_count,
    MIN(created_at) AS first_at,
    MAX(created_at) AS last_at
FROM allocation_stats_projection
WHERE indication_normalized_id IN (:indicationIds)
SQL;

        /** @var array{
         *     allocation_count: int|string|null,
         *     hospital_count: int|string|null,
         *     dispatch_area_count: int|string|null,
         *     state_count: int|string|null,
         *     first_at: string|\DateTimeInterface|null,
         *     last_at: string|\DateTimeInterface|null
         * }|false $row
         */
        $row = $this->connection->fetchAssociative(
            $sql,
            ['indicationIds' => $indicationIds],
            ['indicationIds' => ArrayParameterType::INTEGER],
        );

        if (false === $row) {
            return [
                'allocation_count' => 0,
                'hospital_count' => 0,
                'dispatch_area_count' => 0,
                'state_count' => 0,
                'first_at' => null,
                'last_at' => null,
            ];
        }

        return $row;
    }

    private function fetchTotalAllocations(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*)::int FROM allocation_stats_projection');
    }

    /**
     * @return list<array{year: int, count: int}>
     */
    private function fetchYears(CatalogDimensionKey $dimension, int $entityId): array
    {
        $column = $dimension->projectionColumn();
        $sql = <<<SQL
SELECT created_year AS year, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$column} = :entityId
  AND created_year IS NOT NULL
GROUP BY created_year
ORDER BY created_year ASC
SQL;

        /** @var list<array{year: int|string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['entityId' => $entityId]);

        return $this->mapYearRows($rows);
    }

    /**
     * @param list<int> $indicationIds
     *
     * @return list<array{year: int, count: int}>
     */
    private function fetchYearsForIndicationIds(array $indicationIds): array
    {
        $sql = <<<'SQL'
SELECT created_year AS year, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE indication_normalized_id IN (:indicationIds)
  AND created_year IS NOT NULL
GROUP BY created_year
ORDER BY created_year ASC
SQL;

        /** @var list<array{year: int|string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['indicationIds' => $indicationIds],
            ['indicationIds' => ArrayParameterType::INTEGER],
        );

        return $this->mapYearRows($rows);
    }

    /**
     * @param list<array{year: int|string, count: int|string}> $rows
     *
     * @return list<array{year: int, count: int}>
     */
    private function mapYearRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }
}
