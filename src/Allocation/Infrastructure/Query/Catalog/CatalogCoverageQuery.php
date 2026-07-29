<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogPrivacyPolicy;
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
