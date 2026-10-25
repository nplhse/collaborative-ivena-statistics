<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Application\Contracts\ImportSourceRangeReaderInterface;
use App\Import\Application\ExportPeriod\ExportPeriod;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DoctrineImportSourceRangeReader implements ImportSourceRangeReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    #[\Override]
    public function allocationRange(int $importId): ?array
    {
        return $this->range('SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM allocation WHERE import_id = :import', $importId);
    }

    #[\Override]
    public function closureRange(int $importId): ?array
    {
        return $this->range('SELECT MIN(starts_at) AS first_at, MAX(ends_at) AS last_at FROM closure_interval WHERE import_id = :import', $importId);
    }

    #[\Override]
    public function allocationExportPeriods(int $hospitalId): array
    {
        /** @var list<array{export_starts_at: string, export_ends_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT export_starts_at, export_ends_at
FROM import
WHERE hospital_id = :hospital
  AND type = :type
  AND status IN (:statuses)
  AND export_starts_at IS NOT NULL
  AND export_ends_at > export_starts_at
ORDER BY export_starts_at ASC
SQL,
            [
                'hospital' => $hospitalId,
                'type' => ImportType::ALLOCATION->value,
                'statuses' => [ImportStatus::COMPLETED->value, ImportStatus::PARTIAL->value],
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'type' => ParameterType::STRING,
                'statuses' => ArrayParameterType::STRING,
            ],
        );

        return array_map(
            static fn (array $row): ExportPeriod => new ExportPeriod(
                new \DateTimeImmutable($row['export_starts_at']),
                new \DateTimeImmutable($row['export_ends_at']),
            ),
            $rows,
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(string $sql, int $importId): ?array
    {
        $row = $this->connection->fetchAssociative($sql, ['import' => $importId], ['import' => ParameterType::INTEGER]);
        if (!\is_array($row) || !\is_string($row['first_at'] ?? null) || !\is_string($row['last_at'] ?? null)) {
            return null;
        }

        return [new \DateTimeImmutable($row['first_at']), new \DateTimeImmutable($row['last_at'])];
    }
}
