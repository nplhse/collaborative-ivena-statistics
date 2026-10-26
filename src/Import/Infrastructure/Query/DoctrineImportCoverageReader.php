<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Application\Contracts\ImportCoverageReaderInterface;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Both queries use the `import_id` indexes and run once per import run, not per page view.
 */
final readonly class DoctrineImportCoverageReader implements ImportCoverageReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    #[\Override]
    public function allocationCoverage(int $importId): ImportDataCoverage
    {
        $range = $this->fetchRange(
            'SELECT COUNT(*) AS records, MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM allocation WHERE import_id = :import',
            $importId,
        );
        if (null === $range) {
            return ImportDataCoverage::empty();
        }

        return new ImportDataCoverage($range[0], $range[1], $range[2], $this->fetchDailyCounts(
            <<<'SQL'
SELECT CAST(created_at AS DATE) AS day, COUNT(*) AS records
FROM allocation
WHERE import_id = :import
GROUP BY 1
SQL,
            $importId,
        ));
    }

    #[\Override]
    public function closureCoverage(int $importId): ImportDataCoverage
    {
        $range = $this->fetchRange(
            'SELECT COUNT(*) AS records, MIN(starts_at) AS first_at, MAX(ends_at) AS last_at FROM closure_interval WHERE import_id = :import',
            $importId,
        );
        if (null === $range) {
            return ImportDataCoverage::empty();
        }

        // Timestamps have second precision, so "ends_at - 1 second" is the last covered instant.
        return new ImportDataCoverage($range[0], $range[1], $range[2], $this->fetchDailyCounts(
            <<<'SQL'
SELECT CAST(day AS DATE) AS day, COUNT(*) AS records
FROM closure_interval ci
CROSS JOIN LATERAL generate_series(
    CAST(ci.starts_at AS DATE),
    GREATEST(CAST(ci.starts_at AS DATE), CAST(ci.ends_at - INTERVAL '1 second' AS DATE)),
    INTERVAL '1 day'
) AS day
WHERE ci.import_id = :import
GROUP BY 1
SQL,
            $importId,
        ));
    }

    /**
     * @return array{0: int, 1: \DateTimeImmutable, 2: \DateTimeImmutable}|null
     */
    private function fetchRange(string $sql, int $importId): ?array
    {
        $row = $this->connection->fetchAssociative($sql, ['import' => $importId], ['import' => ParameterType::INTEGER]);
        if (!\is_array($row) || !\is_string($row['first_at'] ?? null) || !\is_string($row['last_at'] ?? null)) {
            return null;
        }
        $firstAt = new \DateTimeImmutable($row['first_at']);
        $lastAt = new \DateTimeImmutable($row['last_at']);

        return [(int) $row['records'], $firstAt, max($firstAt, $lastAt)];
    }

    /**
     * @return array<string, int>
     */
    private function fetchDailyCounts(string $sql, int $importId): array
    {
        /** @var list<array{day: string, records: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['import' => $importId], ['import' => ParameterType::INTEGER]);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['day']] = (int) $row['records'];
        }

        return $counts;
    }
}
