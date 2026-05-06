<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LegacyImportMigrator
{
    public function __construct(
        private Connection $legacyConnection,
        private Connection $defaultConnection,
        private EntityManagerInterface $entityManager,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function migrate(bool $dryRun = false, ?int $legacyImportId = null): int
    {
        $sql = 'SELECT id, user_id, hospital_id, name, status, created_at, updated_at, file_path, file_extension, file_mime_type, file_size, row_count, run_count, runtime FROM import';
        $params = [];
        if (null !== $legacyImportId) {
            $sql .= ' WHERE id = :id';
            $params['id'] = $legacyImportId;
        }
        $sql .= ' ORDER BY id ASC';
        $rows = $this->legacyConnection->fetchAllAssociative($sql, $params);
        $migrated = 0;

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $existingMapping = $this->defaultConnection->fetchAssociative(
                'SELECT id FROM legacy_migration_import_mapping WHERE legacy_import_id = :id',
                ['id' => $legacyId]
            );
            if (false !== $existingMapping) {
                continue;
            }

            $mappedUserId = $this->defaultConnection->fetchOne(
                'SELECT new_user_id FROM legacy_migration_user_mapping WHERE legacy_user_id = :id',
                ['id' => (int) $row['user_id']]
            );
            if (false === $mappedUserId) {
                throw new \RuntimeException(sprintf('Missing user mapping for legacy import %d.', $legacyId));
            }
            $mappedHospitalId = $this->defaultConnection->fetchOne(
                'SELECT new_hospital_id FROM legacy_migration_hospital_mapping WHERE legacy_hospital_id = :id',
                ['id' => (int) $row['hospital_id']]
            );
            if (false === $mappedHospitalId) {
                throw new \RuntimeException(sprintf('Missing hospital mapping for legacy import %d.', $legacyId));
            }

            if ($dryRun) {
                ++$migrated;
                continue;
            }

            /** @var User $user */
            $user = $this->entityManager->getReference(User::class, (int) $mappedUserId);
            /** @var Hospital $hospital */
            $hospital = $this->entityManager->getReference(Hospital::class, (int) $mappedHospitalId);

            $import = (new Import())
                ->setName((string) ($row['name'] ?? sprintf('Legacy Import %d', $legacyId)))
                ->setHospital($hospital)
                ->setStatus($this->mapStatus((string) ($row['status'] ?? 'pending')))
                ->setType(ImportType::ALLOCATION)
                ->setFilePath((string) ($row['file_path'] ?? sprintf('legacy://import/%d', $legacyId)))
                ->setFileExtension((string) ($row['file_extension'] ?? 'legacy'))
                ->setFileMimeType((string) ($row['file_mime_type'] ?? 'application/octet-stream'))
                ->setFileSize(max(0, (int) ($row['file_size'] ?? 0)))
                ->setRowCount(max(0, (int) ($row['row_count'] ?? 0)))
                ->setRunCount(max(0, (int) ($row['run_count'] ?? 0)))
                ->setRunTime(max(0, (int) ($row['runtime'] ?? 0)))
                ->setRowsPassed(0)
                ->setRowsRejected(0)
                ->setCreatedBy($user)
                ->setUpdatedBy($user);

            if (!empty($row['created_at'])) {
                $import->setCreatedAt(new \DateTimeImmutable((string) $row['created_at']));
            }
            if (!empty($row['updated_at'])) {
                $import->setUpdatedAt(new \DateTimeImmutable((string) $row['updated_at']));
            }

            $this->entityManager->persist($import);
            $this->entityManager->flush();

            $this->defaultConnection->insert('legacy_migration_import_mapping', [
                'legacy_import_id' => $legacyId,
                'new_import_id' => (int) $import->getId(),
                'status' => 'pending',
                'last_allocation_id' => null,
                'migrated_count' => 0,
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            ++$migrated;
        }

        $this->stateRepository->log('imports', 'info', 'imports phase finished', $legacyImportId, ['migrated' => $migrated, 'dryRun' => $dryRun]);

        return $migrated;
    }

    private function mapStatus(string $status): ImportStatus
    {
        return match (mb_strtolower(trim($status))) {
            'done', 'completed' => ImportStatus::COMPLETED,
            'running' => ImportStatus::RUNNING,
            'failed' => ImportStatus::FAILED,
            default => ImportStatus::PENDING,
        };
    }
}

