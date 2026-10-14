<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Data coverage of a hospital from the export periods of its completed imports.
 * Returns null when no completed import exists or one has not been derived yet, so callers can
 * fall back to the legacy "day with at least one allocation" heuristic.
 */
final readonly class ClosureCoverageQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function allocations(int $hospitalId): ?ClosureCoverage
    {
        return $this->coverage($hospitalId, 'Allocation');
    }

    public function closures(int $hospitalId): ?ClosureCoverage
    {
        return $this->coverage($hospitalId, 'Closure');
    }

    private function coverage(int $hospitalId, string $type): ?ClosureCoverage
    {
        /** @var list<array{export_starts_at: ?string, export_ends_at: ?string, export_period_source: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT export_starts_at, export_ends_at, export_period_source
FROM import
WHERE hospital_id = :hospital
  AND type = :type
  AND status IN ('Completed', 'Partial')
ORDER BY id ASC
SQL,
            ['hospital' => $hospitalId, 'type' => $type],
            ['hospital' => ParameterType::INTEGER, 'type' => ParameterType::STRING],
        );
        if ([] === $rows) {
            return null;
        }
        $ranges = [];
        foreach ($rows as $row) {
            if (null === $row['export_period_source']) {
                return null;
            }
            if (null === $row['export_starts_at'] || null === $row['export_ends_at']) {
                continue;
            }
            $ranges[] = [new \DateTimeImmutable($row['export_starts_at']), new \DateTimeImmutable($row['export_ends_at'])];
        }

        return ClosureCoverage::fromRanges($ranges);
    }
}
