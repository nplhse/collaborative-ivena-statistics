<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class IndicationRawOccurrenceQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countOpen(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)::int
FROM indication_raw r
WHERE r.review_status IN ('unreviewed', 'needs_review')
SQL,
        );
    }

    public function countBySegment(string $segment): int
    {
        [$where, $params] = $this->segmentWhereClause($segment);

        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*)::int FROM indication_raw r WHERE %s', $where),
            $params,
        );
    }

    /**
     * @param list<int> $rawIds
     *
     * @return array<int, array{occurrence_count: int, primary_count: int, secondary_count: int}>
     */
    public function fetchCountsForIds(array $rawIds): array
    {
        if ([] === $rawIds) {
            return [];
        }

        /** @var list<array{raw_id: int|string, occurrence_count: int|string, primary_count: int|string, secondary_count: int|string}> $rows */
        $rows = $this->connection->executeQuery(
            <<<'SQL'
SELECT r.id AS raw_id,
       COALESCE(p.cnt, 0) + COALESCE(s.cnt, 0) AS occurrence_count,
       COALESCE(p.cnt, 0) AS primary_count,
       COALESCE(s.cnt, 0) AS secondary_count
FROM indication_raw r
LEFT JOIN (
    SELECT indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    GROUP BY indication_raw_id
) p ON p.raw_id = r.id
LEFT JOIN (
    SELECT secondary_indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    WHERE secondary_indication_raw_id IS NOT NULL
    GROUP BY secondary_indication_raw_id
) s ON s.raw_id = r.id
WHERE r.id IN (:rawIds)
SQL,
            ['rawIds' => $rawIds],
            ['rawIds' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['raw_id'];
            $result[$id] = [
                'occurrence_count' => (int) $row['occurrence_count'],
                'primary_count' => (int) $row['primary_count'],
                'secondary_count' => (int) $row['secondary_count'],
            ];
        }

        return $result;
    }

    public function fetchOccurrenceCount(int $rawId): int
    {
        $counts = $this->fetchCountsForIds([$rawId]);

        return $counts[$rawId]['occurrence_count'] ?? 0;
    }

    /**
     * @return list<array{id: int, public_id: string, created_at: string, hospital_name: string|null}>
     */
    public function fetchSampleAllocations(int $rawId, int $limit = 5): array
    {
        /** @var list<array{id: int|string, public_id: string, created_at: string, hospital_name: string|null}> $rows */
        $rows = $this->connection->executeQuery(
            <<<'SQL'
SELECT a.id,
       a.public_id,
       a.created_at,
       h.name AS hospital_name
FROM allocation a
LEFT JOIN hospital h ON h.id = a.hospital_id
WHERE a.indication_raw_id = :rawId
   OR a.secondary_indication_raw_id = :rawId
ORDER BY a.created_at DESC
LIMIT :limit
SQL,
            ['rawId' => $rawId, 'limit' => $limit],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'public_id' => $row['public_id'],
            'created_at' => $row['created_at'],
            'hospital_name' => $row['hospital_name'],
        ], $rows);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function segmentWhereClause(string $segment): array
    {
        return match ($segment) {
            'open' => ["r.review_status IN ('unreviewed', 'needs_review')", []],
            'unreviewed' => ["r.review_status = 'unreviewed'", []],
            'needs_review' => ["r.review_status = 'needs_review'", []],
            'new' => [
                "r.review_status IN ('unreviewed', 'needs_review') AND r.created_at >= :since",
                ['since' => new \DateTimeImmutable('-30 days')->format('Y-m-d H:i:s')],
            ],
            'top_open' => ["r.review_status IN ('unreviewed', 'needs_review')", []],
            'matched' => ["r.review_status = 'matched'", []],
            'not_matchable' => ["r.review_status = 'not_matchable'", []],
            'ignored' => ["r.review_status = 'ignored'", []],
            default => ["r.review_status IN ('unreviewed', 'needs_review')", []],
        };
    }
}
