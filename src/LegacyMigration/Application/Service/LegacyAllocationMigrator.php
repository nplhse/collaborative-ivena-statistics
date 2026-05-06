<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\DoctrineAllocationPersister;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\LegacyMigration\Infrastructure\Mapper\LegacyAllocationRowMapper;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class LegacyAllocationMigrator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $legacyConnection,
        private Connection $defaultConnection,
        private EntityManagerInterface $entityManager,
        private LegacyAllocationRowMapper $rowMapper,
        private AllocationImportFactory $allocationImportFactory,
        private DoctrineAllocationPersister $persister,
        private ValidatorInterface $validator,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function migrate(bool $dryRun, int $batchSize, bool $resume, bool $withProgress, ?int $onlyLegacyImportId = null): int
    {
        $sql = 'SELECT legacy_import_id, new_import_id, status, last_allocation_id, migrated_count FROM legacy_migration_import_mapping';
        $params = [];
        if (null !== $onlyLegacyImportId) {
            $sql .= ' WHERE legacy_import_id = :legacyImportId';
            $params['legacyImportId'] = $onlyLegacyImportId;
        }
        $sql .= ' ORDER BY legacy_import_id ASC';
        $imports = $this->defaultConnection->fetchAllAssociative($sql, $params);
        $totalMigrated = 0;

        foreach ($imports as $importRow) {
            $legacyImportId = (int) $importRow['legacy_import_id'];
            $newImportId = (int) $importRow['new_import_id'];
            $lastAllocationId = $resume ? (int) ($importRow['last_allocation_id'] ?? 0) : 0;
            $migratedCount = (int) ($importRow['migrated_count'] ?? 0);
            $skippedCount = 0;

            $this->defaultConnection->update('legacy_migration_import_mapping', [
                'status' => 'running',
                'started_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'error_message' => null,
            ], ['legacy_import_id' => $legacyImportId]);

            while (true) {
                $rows = $this->legacyConnection->executeQuery(
                    <<<'SQL'
SELECT
    a.*,
    h.name AS hospital_name,
    d.name AS dispatch_area_name
FROM allocation a
LEFT JOIN hospital h ON h.id = a.hospital_id
LEFT JOIN dispatch_area d ON d.id = a.dispatch_area_id
WHERE a.import_id = :legacyImportId
  AND a.id > :lastAllocationId
ORDER BY a.id ASC
LIMIT :batchSize
SQL,
                    [
                        'legacyImportId' => $legacyImportId,
                        'lastAllocationId' => $lastAllocationId,
                        'batchSize' => $batchSize,
                    ],
                    [
                        'legacyImportId' => ParameterType::INTEGER,
                        'lastAllocationId' => ParameterType::INTEGER,
                        'batchSize' => ParameterType::INTEGER,
                    ]
                )->fetchAllAssociative();

                if ([] === $rows) {
                    break;
                }

                try {
                    $this->defaultConnection->beginTransaction();
                    /** @var Import $import */
                    $import = $this->entityManager->find(Import::class, $newImportId);
                    if (!$import instanceof Import) {
                        throw new \RuntimeException(sprintf('Import %d for legacy import %d not found.', $newImportId, $legacyImportId));
                    }

                    $this->allocationImportFactory->warm();

                    foreach ($rows as $row) {
                        $legacyAllocationId = (int) $row['id'];
                        $already = (int) $this->defaultConnection->fetchOne(
                            'SELECT COUNT(*) FROM legacy_migration_allocation_mapping WHERE legacy_allocation_id = :id',
                            ['id' => $legacyAllocationId]
                        );
                        if ($already > 0) {
                            $lastAllocationId = $legacyAllocationId;
                            continue;
                        }

                        try {
                            $dto = $this->rowMapper->mapAssoc($row);
                            $violations = $this->validator->validate($dto);
                            if (\count($violations) > 0) {
                                throw new \RuntimeException(sprintf('Validation failed: %s', (string) $violations));
                            }

                            $allocation = $this->allocationImportFactory->fromDto($dto, $import);
                            if ($dryRun) {
                                $lastAllocationId = $legacyAllocationId;
                                ++$migratedCount;
                                ++$totalMigrated;
                                continue;
                            }

                            $this->persister->persist($allocation);
                            $this->persister->flush();
                            $this->defaultConnection->insert('legacy_migration_allocation_mapping', [
                                'legacy_allocation_id' => $legacyAllocationId,
                                'new_allocation_id' => (int) $allocation->getId(),
                                'legacy_import_id' => $legacyImportId,
                                'migrated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                            ]);

                            $lastAllocationId = $legacyAllocationId;
                            ++$migratedCount;
                            ++$totalMigrated;
                        } catch (\Throwable $rowError) {
                            $lastAllocationId = $legacyAllocationId;
                            ++$skippedCount;
                            $this->stateRepository->log(
                                'allocations',
                                'warning',
                                sprintf(
                                    'Skipping legacy allocation %d (legacy import %d): %s',
                                    $legacyAllocationId,
                                    $legacyImportId,
                                    $rowError->getMessage()
                                ),
                                $legacyAllocationId,
                                ['legacyImportId' => $legacyImportId]
                            );
                            continue;
                        }
                    }

                    $this->defaultConnection->update('legacy_migration_import_mapping', [
                        'last_allocation_id' => $lastAllocationId,
                        'migrated_count' => $migratedCount,
                        'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    ], ['legacy_import_id' => $legacyImportId]);

                    $this->defaultConnection->commit();
                } catch (\Throwable $e) {
                    if ($this->defaultConnection->isTransactionActive()) {
                        $this->defaultConnection->rollBack();
                    }
                    $this->defaultConnection->update('legacy_migration_import_mapping', [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    ], ['legacy_import_id' => $legacyImportId]);
                    $this->stateRepository->log('allocations', 'error', $e->getMessage(), $legacyImportId);
                    throw $e;
                }

                if ($withProgress) {
                    // no-op, progress is logged by command-level reporter
                }
            }

            if (!$dryRun) {
                $this->projectionRebuilder->rebuildForImport($newImportId);
            }
            $this->defaultConnection->update('legacy_migration_import_mapping', [
                'status' => 'done',
                'finished_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ], ['legacy_import_id' => $legacyImportId]);
            if ($skippedCount > 0) {
                $this->stateRepository->log(
                    'allocations',
                    'warning',
                    sprintf('Import %d finished with %d skipped allocation rows.', $legacyImportId, $skippedCount),
                    $legacyImportId
                );
            }
        }

        return $totalMigrated;
    }
}
