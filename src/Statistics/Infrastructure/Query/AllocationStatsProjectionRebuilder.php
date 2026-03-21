<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Fills allocation_stats_projection via DBAL (no ORM writes).
 */
final class AllocationStatsProjectionRebuilder implements AllocationStatsProjectionRebuildInterface
{
    private const int BATCH_SIZE = 250;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function rebuildForImport(int $importId): void
    {
        $this->connection->beginTransaction();

        try {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM allocation_stats_projection WHERE import_id = :importId',
                ['importId' => $importId]
            );

            $this->logger->info('allocation_stats_projection.deleted', [
                'import_id' => $importId,
                'rows' => $deleted,
            ]);

            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT
  a.id,
  a.import_id,
  a.hospital_id,
  a.state_id,
  a.dispatch_area_id,
  a.speciality_id,
  a.department_id,
  a.occasion_id,
  a.assignment_id,
  a.infection_id,
  a.indication_normalized_id,
  a.created_at,
  a.arrival_at,
  a.age,
  a.gender,
  a.urgency,
  a.transport_type,
  a.requires_resus,
  a.requires_cathlab,
  a.is_cpr,
  a.is_ventilated,
  a.is_with_physician
FROM allocation a
WHERE a.import_id = :importId
ORDER BY a.id ASC
SQL,
                ['importId' => $importId]
            );

            foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
                $this->insertChunk($chunk);
            }

            $this->connection->commit();

            $this->logger->info('allocation_stats_projection.rebuilt', [
                'import_id' => $importId,
                'rows' => \count($rows),
            ]);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('allocation_stats_projection.rebuild_failed', [
                'import_id' => $importId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $chunk
     */
    private function insertChunk(array $chunk): void
    {
        if ([] === $chunk) {
            return;
        }

        $columns = [
            'id', 'import_id', 'hospital_id', 'state_id', 'dispatch_area_id',
            'speciality_id', 'department_id', 'occasion_id', 'assignment_id',
            'infection_id', 'indication_normalized_id',
            'created_at', 'arrival_at',
            'created_year', 'created_quarter', 'created_month', 'created_week',
            'created_day', 'created_weekday', 'created_hour',
            'transport_time_minutes',
            'age', 'gender_code', 'urgency_code', 'transport_type_code',
            'requires_resus', 'requires_cathlab', 'is_cpr', 'is_ventilated', 'is_with_physician',
        ];

        $params = [];
        $types = [];
        $valueTuples = [];
        $rowIndex = 0;

        foreach ($chunk as $row) {
            $mapped = $this->mapRow($row);
            $placeholders = [];

            foreach ($columns as $col) {
                $param = $col.'_'.$rowIndex;
                $placeholders[] = ':'.$param;
                $params[$param] = $mapped[$col];
                $types[$param] = $this->doctrineTypeNameForProjectionColumn($col);
            }

            $valueTuples[] = '('.implode(', ', $placeholders).')';
            ++$rowIndex;
        }

        $sql = sprintf(
            'INSERT INTO allocation_stats_projection (%s) VALUES %s',
            implode(', ', $columns),
            implode(', ', $valueTuples)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    /**
     * DBAL resolves these names to {@see \Doctrine\DBAL\Types\Type} instances (Psalm-compatible $types array).
     */
    private function doctrineTypeNameForProjectionColumn(string $column): string
    {
        return match ($column) {
            'created_at', 'arrival_at' => Types::STRING,
            'requires_resus', 'requires_cathlab', 'is_cpr', 'is_ventilated', 'is_with_physician' => Types::BOOLEAN,
            default => Types::INTEGER,
        };
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        $createdAt = $this->parseDateTimeImmutable($row['created_at'] ?? null);
        $arrivalAt = $this->parseDateTimeImmutable($row['arrival_at'] ?? null);

        if (null === $createdAt || null === $arrivalAt) {
            throw new \InvalidArgumentException('allocation row missing created_at or arrival_at');
        }

        $transportMinutes = (int) round(($arrivalAt->getTimestamp() - $createdAt->getTimestamp()) / 60);

        $year = (int) $createdAt->format('Y');
        $month = (int) $createdAt->format('n');
        $quarter = (int) ceil($month / 3);

        $urgency = AllocationStatsUrgencyProjectionCode::tryFromDbValue($row['urgency'] ?? null);
        if (null === $urgency) {
            throw new \InvalidArgumentException('allocation row has invalid urgency: '.var_export($row['urgency'] ?? null, true));
        }

        return [
            'id' => (int) $row['id'],
            'import_id' => (int) $row['import_id'],
            'hospital_id' => (int) $row['hospital_id'],
            'state_id' => (int) $row['state_id'],
            'dispatch_area_id' => (int) $row['dispatch_area_id'],
            'speciality_id' => (int) $row['speciality_id'],
            'department_id' => (int) $row['department_id'],
            'occasion_id' => null !== ($row['occasion_id'] ?? null) ? (int) $row['occasion_id'] : null,
            'assignment_id' => (int) $row['assignment_id'],
            'infection_id' => null !== ($row['infection_id'] ?? null) ? (int) $row['infection_id'] : null,
            'indication_normalized_id' => null !== ($row['indication_normalized_id'] ?? null) ? (int) $row['indication_normalized_id'] : null,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'arrival_at' => $arrivalAt->format('Y-m-d H:i:s'),
            'created_year' => $year,
            'created_quarter' => $quarter,
            'created_month' => $month,
            'created_week' => (int) $createdAt->format('W'),
            'created_day' => (int) $createdAt->format('j'),
            'created_weekday' => (int) $createdAt->format('N'),
            'created_hour' => (int) $createdAt->format('G'),
            'transport_time_minutes' => $transportMinutes,
            'age' => \array_key_exists('age', $row)
                ? (null === $row['age'] ? null : (int) $row['age'])
                : null,
            'gender_code' => AllocationStatsGenderProjectionCode::tryFromDbLetter(
                isset($row['gender']) && \is_string($row['gender']) ? $row['gender'] : null
            )?->value,
            'urgency_code' => $urgency->value,
            'transport_type_code' => AllocationStatsTransportTypeProjectionCode::tryFromDbLetter(
                isset($row['transport_type']) && \is_string($row['transport_type']) ? $row['transport_type'] : null
            )?->value,
            'requires_resus' => $this->nullableBool($row['requires_resus'] ?? null),
            'requires_cathlab' => $this->nullableBool($row['requires_cathlab'] ?? null),
            'is_cpr' => $this->nullableBool($row['is_cpr'] ?? null),
            'is_ventilated' => $this->nullableBool($row['is_ventilated'] ?? null),
            'is_with_physician' => $this->nullableBool($row['is_with_physician'] ?? null),
        ];
    }

    private function nullableBool(mixed $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return 0 !== (int) $value;
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (\is_string($value)) {
            $v = strtolower(trim($value, " \t\n\r\0\x0B"));

            $parsed = match ($v) {
                '', 'null' => null,
                '1', 't', 'true', 'yes', 'on' => true,
                '0', 'f', 'false', 'no', 'off' => false,
                default => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            };

            return $parsed;
        }

        return (bool) $value;
    }

    private function parseDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (\is_string($value) && '' !== $value) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value);

            return $dt ?: new \DateTimeImmutable($value);
        }

        return null;
    }
}
