<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Hospital\DTO\HospitalParticipatingSinceBackfillRow;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Enum\ImportStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class HospitalParticipatingSinceBackfillService
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<HospitalParticipatingSinceBackfillRow>
     */
    public function preview(): array
    {
        return $this->decisions();
    }

    /**
     * @return list<HospitalParticipatingSinceBackfillRow>
     */
    public function apply(): array
    {
        $rows = $this->decisions();
        $updates = [];
        foreach ($rows as $row) {
            if ($row->participatingSince instanceof \DateTimeImmutable) {
                $updates[$row->hospitalId] = $row->participatingSince;
            }
        }

        $this->write($updates);

        return $rows;
    }

    /**
     * @return list<HospitalParticipatingSinceBackfillRow>
     */
    private function decisions(): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT h.id, h.name
            FROM hospital h
            WHERE h.is_participating = true
              AND h.participating_since IS NULL
            ORDER BY h.id ASC
            SQL,
        );

        $fromAudit = $this->firstParticipatingAtFromAudit();
        $fromImport = $this->firstSuccessfulImportAt();

        $rows = [];
        foreach ($candidates as $candidate) {
            $hospitalId = (int) $candidate['id'];
            $name = (string) $candidate['name'];
            $auditAt = $fromAudit[$hospitalId] ?? null;
            if ($auditAt instanceof \DateTimeImmutable) {
                $rows[] = new HospitalParticipatingSinceBackfillRow(
                    $hospitalId,
                    $name,
                    'audit',
                    $auditAt,
                );

                continue;
            }

            $importAt = $fromImport[$hospitalId] ?? null;
            if ($importAt instanceof \DateTimeImmutable) {
                $rows[] = new HospitalParticipatingSinceBackfillRow(
                    $hospitalId,
                    $name,
                    'import',
                    $importAt,
                );

                continue;
            }

            $rows[] = new HospitalParticipatingSinceBackfillRow(
                $hospitalId,
                $name,
                null,
                null,
            );
        }

        return $rows;
    }

    /**
     * @return array<int, \DateTimeImmutable>
     */
    private function firstParticipatingAtFromAudit(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT al.entity_id AS hospital_id, MIN(al.occurred_at) AS first_at
            FROM audit_log al
            WHERE al.entity_class = :entityClass
              AND al.entity_id IS NOT NULL
              AND (
                (
                    al.action = 'create'
                    AND (al.changes #>> '{isParticipating,new}') = 'true'
                )
                OR (
                    al.action = 'update'
                    AND (al.changes #>> '{isParticipating,old}') = 'false'
                    AND (al.changes #>> '{isParticipating,new}') = 'true'
                )
              )
            GROUP BY al.entity_id
            SQL,
            ['entityClass' => Hospital::class],
        );

        return $this->indexDateTimesByHospitalId($rows);
    }

    /**
     * @return array<int, \DateTimeImmutable>
     */
    private function firstSuccessfulImportAt(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT i.hospital_id, MIN(i.created_at) AS first_at
            FROM import i
            WHERE i.status IN (:statuses)
            GROUP BY i.hospital_id
            SQL,
            [
                'statuses' => [
                    ImportStatus::COMPLETED->value,
                    ImportStatus::PARTIAL->value,
                ],
            ],
            [
                'statuses' => ArrayParameterType::STRING,
            ],
        );

        return $this->indexDateTimesByHospitalId($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function indexDateTimesByHospitalId(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $hospitalId = (int) ($row['hospital_id'] ?? 0);
            $firstAt = $this->parseDateTime($row['first_at'] ?? null);
            if ($hospitalId < 1 || !$firstAt instanceof \DateTimeImmutable) {
                continue;
            }

            $result[$hospitalId] = $firstAt;
        }

        return $result;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * @param array<int, \DateTimeImmutable> $updates
     */
    private function write(array $updates): void
    {
        if ([] === $updates) {
            return;
        }

        $this->connection->beginTransaction();

        try {
            foreach ($updates as $hospitalId => $participatingSince) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    UPDATE hospital
                    SET participating_since = :participatingSince
                    WHERE id = :id
                      AND is_participating = true
                      AND participating_since IS NULL
                    SQL,
                    [
                        'participatingSince' => $participatingSince,
                        'id' => $hospitalId,
                    ],
                    [
                        'participatingSince' => Types::DATETIME_IMMUTABLE,
                        'id' => Types::INTEGER,
                    ],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }
}
