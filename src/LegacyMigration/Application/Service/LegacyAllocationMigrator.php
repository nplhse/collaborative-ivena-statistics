<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Audit\ImportRunSuppressedAuditClasses;
use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Domain\Entity\Import;
use App\LegacyMigration\Application\Contract\LegacyAllocationImportFactoryInterface;
use App\LegacyMigration\Application\Contract\LegacyMigrationProgressInterface;
use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\LegacyMigration\Infrastructure\Mapper\LegacyAllocationRowMapper;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class LegacyAllocationMigrator
{
    private const int MAPPING_INSERT_CHUNK = 250;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $legacyConnection,
        private Connection $defaultConnection,
        private EntityManagerInterface $entityManager,
        private LegacyAllocationRowMapper $rowMapper,
        private LegacyAllocationImportFactoryInterface $allocationImportFactory,
        private AllocationPersisterInterface $persister,
        private ValidatorInterface $validator,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
        private AuditContext $auditContext,
    ) {
    }

    public function migrate(
        bool $dryRun,
        int $batchSize,
        bool $resume,
        LegacyMigrationProgressInterface $progress,
        LegacyMigrationRunControl $runControl,
        ?int $onlyLegacyImportId = null,
        bool $skipProjection = false,
        bool $force = false,
    ): int {
        unset($resume);

        $imports = $this->fetchImportMappings($onlyLegacyImportId, $force);
        $importCount = \count($imports);
        $totalMigrated = 0;

        if (0 === $importCount) {
            $progress->startPhase('Allocations', 0);
            $progress->finishPhase();

            return 0;
        }

        $allocationTotalsByImport = $this->loadAllocationTotalsByImport();

        $overallMax = 0;
        $overallStart = 0;
        foreach ($imports as $importRow) {
            $legacyImportId = (int) $importRow['legacy_import_id'];
            $importTotal = $allocationTotalsByImport[$legacyImportId] ?? 0;
            $overallMax += $importTotal;
            $overallStart += min((int) ($importRow['migrated_count'] ?? 0), $importTotal);
        }

        $progress->startPhase('Allocations', $overallMax);
        if ($overallStart > 0) {
            $progress->advance($overallStart);
        }

        $this->allocationImportFactory->warm();

        $this->auditContext->pushSuppressedEntityAudit(ImportRunSuppressedAuditClasses::fqcnList());
        try {
            $importIndex = 0;
            foreach ($imports as $importRow) {
                $runControl->throwIfStopRequested();
                ++$importIndex;
                $legacyImportId = (int) $importRow['legacy_import_id'];
                $newImportId = (int) $importRow['new_import_id'];
                $status = (string) ($importRow['status'] ?? 'pending');
                $migratedCount = (int) ($importRow['migrated_count'] ?? 0);
                $importTotal = $allocationTotalsByImport[$legacyImportId] ?? 0;

                if ($this->isImportComplete($status, $migratedCount, $importTotal)) {
                    $progress->setMessage($this->formatAllocationProgressMessage(
                        $importIndex,
                        $importCount,
                        $legacyImportId,
                        $migratedCount,
                        $importTotal,
                    ));
                    continue;
                }

                $lastAllocationId = $this->resolveLastAllocationId($importRow);
                $skippedCount = 0;
                $migratedIds = $this->shouldLoadMigratedAllocationIds($status, $force)
                    ? $this->loadMigratedAllocationIds($legacyImportId)
                    : [];

                $progress->setMessage($this->formatAllocationProgressMessage(
                    $importIndex,
                    $importCount,
                    $legacyImportId,
                    $migratedCount,
                    $importTotal,
                ));

                $this->defaultConnection->update('legacy_migration_import_mapping', [
                    'status' => 'running',
                    'started_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    'error_message' => null,
                ], ['legacy_import_id' => $legacyImportId]);

                while (true) {
                    $runControl->throwIfStopRequested();
                    $result = $this->fetchLegacyAllocationBatch($legacyImportId, $lastAllocationId, $batchSize);
                    $rowSeen = false;

                    try {
                        $this->defaultConnection->beginTransaction();
                        /** @var Import $import */
                        $import = $this->entityManager->getReference(Import::class, $newImportId);

                        /** @var list<array{legacyAllocationId: int, allocation: Allocation}> $pendingMappings */
                        $pendingMappings = [];
                        $batchProcessed = 0;

                        foreach ($result->iterateAssociative() as $row) {
                            $runControl->throwIfStopRequested();
                            $rowSeen = true;
                            ++$batchProcessed;
                            $legacyAllocationId = (int) $row['id'];
                            if (isset($migratedIds[$legacyAllocationId])) {
                                $lastAllocationId = $legacyAllocationId;
                                continue;
                            }

                            try {
                                $dto = $this->rowMapper->mapAssoc($row);
                                $violations = $this->validator->validate($dto);
                                if (\count($violations) > 0) {
                                    throw new \RuntimeException(sprintf('Validation failed: %s', (string) $violations));
                                }

                                if ($dryRun) {
                                    $migratedIds[$legacyAllocationId] = true;
                                    $lastAllocationId = $legacyAllocationId;
                                    ++$migratedCount;
                                    ++$totalMigrated;
                                    continue;
                                }

                                $allocation = $this->allocationImportFactory->fromDto($dto, $import);
                                $this->persister->persist($allocation);
                                $pendingMappings[] = [
                                    'legacyAllocationId' => $legacyAllocationId,
                                    'allocation' => $allocation,
                                ];
                                $migratedIds[$legacyAllocationId] = true;
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
                            }
                        }

                        if (!$rowSeen) {
                            if ($this->defaultConnection->isTransactionActive()) {
                                $this->defaultConnection->rollBack();
                            }
                            break;
                        }

                        if (!$dryRun && [] !== $pendingMappings) {
                            $this->persister->flush();
                            $this->insertAllocationMappings($pendingMappings, $legacyImportId);
                        }

                        $this->defaultConnection->update('legacy_migration_import_mapping', [
                            'last_allocation_id' => $lastAllocationId,
                            'migrated_count' => $migratedCount,
                            'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                        ], ['legacy_import_id' => $legacyImportId]);

                        $this->defaultConnection->commit();

                        $progress->advance($batchProcessed);
                        $progress->setMessage($this->formatAllocationProgressMessage(
                            $importIndex,
                            $importCount,
                            $legacyImportId,
                            $migratedCount,
                            $importTotal,
                        ));
                    } catch (LegacyMigrationInterruptedException $e) {
                        if ($this->defaultConnection->isTransactionActive()) {
                            $this->defaultConnection->rollBack();
                        }
                        throw $e;
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
                    } finally {
                        $result->free();
                    }
                }

                if (!$dryRun && !$skipProjection) {
                    $progress->note(sprintf('Rebuilding stats projection for legacy import #%d…', $legacyImportId));
                    $this->projectionRebuilder->rebuildForImport($newImportId);
                }

                $this->markImportDone($legacyImportId, $migratedCount, $importTotal);

                if ($skippedCount > 0) {
                    $this->stateRepository->log(
                        'allocations',
                        'warning',
                        sprintf('Import %d finished with %d skipped allocation rows.', $legacyImportId, $skippedCount),
                        $legacyImportId
                    );
                }
            }
        } finally {
            $this->auditContext->popSuppressedEntityAudit();
        }

        $progress->finishPhase();

        return $totalMigrated;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchImportMappings(?int $onlyLegacyImportId, bool $force): array
    {
        $sql = 'SELECT legacy_import_id, new_import_id, status, last_allocation_id, migrated_count FROM legacy_migration_import_mapping';
        $conditions = [];
        $params = [];

        if (null !== $onlyLegacyImportId) {
            $conditions[] = 'legacy_import_id = :legacyImportId';
            $params['legacyImportId'] = $onlyLegacyImportId;
        }
        if (!$force) {
            $conditions[] = "status IN ('pending', 'running', 'failed')";
        }
        if ([] !== $conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY legacy_import_id ASC';

        return $this->defaultConnection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return array<int, int>
     */
    private function loadAllocationTotalsByImport(): array
    {
        $rows = $this->legacyConnection->fetchAllAssociative(
            'SELECT import_id, COUNT(*) AS total FROM allocation GROUP BY import_id'
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['import_id']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $importRow
     */
    private function resolveLastAllocationId(array $importRow): int
    {
        $status = (string) ($importRow['status'] ?? 'pending');
        $migratedCount = (int) ($importRow['migrated_count'] ?? 0);

        if ('pending' === $status && 0 === $migratedCount) {
            return 0;
        }

        return (int) ($importRow['last_allocation_id'] ?? 0);
    }

    private function isImportComplete(string $status, int $migratedCount, int $importTotal): bool
    {
        return 'done' === $status && $migratedCount >= $importTotal;
    }

    private function shouldLoadMigratedAllocationIds(string $status, bool $force): bool
    {
        return $force || 'failed' === $status;
    }

    private function markImportDone(int $legacyImportId, int $migratedCount, int $importTotal): void
    {
        $lastAllocationId = $this->fetchMaxLegacyAllocationId($legacyImportId);

        $this->defaultConnection->update('legacy_migration_import_mapping', [
            'status' => 'done',
            'migrated_count' => max($migratedCount, $importTotal),
            'last_allocation_id' => $lastAllocationId,
            'finished_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ], ['legacy_import_id' => $legacyImportId]);
    }

    private function fetchMaxLegacyAllocationId(int $legacyImportId): int
    {
        return (int) $this->legacyConnection->fetchOne(
            'SELECT COALESCE(MAX(id), 0) FROM allocation WHERE import_id = :legacyImportId',
            ['legacyImportId' => $legacyImportId],
        );
    }

    private function formatAllocationProgressMessage(
        int $importIndex,
        int $importCount,
        int $legacyImportId,
        int $migratedCount,
        int $importTotal,
    ): string {
        $importPercent = $importTotal > 0
            ? round(100.0 * (float) $migratedCount / (float) $importTotal, 1)
            : 0.0;

        return sprintf(
            'Overall: import %d/%d | Legacy import #%d: %d/%d (%s%%)',
            $importIndex,
            $importCount,
            $legacyImportId,
            $migratedCount,
            $importTotal,
            (string) $importPercent,
        );
    }

    private function fetchLegacyAllocationBatch(int $legacyImportId, int $lastAllocationId, int $batchSize): Result
    {
        return $this->legacyConnection->executeQuery(
            <<<'SQL'
SELECT
    a.id,
    a.created_at,
    a.arrival_at,
    a.gender,
    a.age,
    a.requires_resus,
    a.requires_cathlab,
    a.is_cpr,
    a.is_ventilated,
    a.is_shock,
    a.is_pregnant,
    a.is_work_accident,
    a.is_with_physician,
    a.mode_of_transport,
    a.urgency,
    a.speciality,
    a.speciality_detail,
    a.speciality_was_closed,
    a.assignment,
    a.occasion,
    a.secondary_deployment,
    a.is_infectious,
    a.indication_code,
    a.indication,
    a.secondary_indication_code,
    a.secondary_indication,
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
        );
    }

    /**
     * @return array<int, true>
     */
    private function loadMigratedAllocationIds(int $legacyImportId): array
    {
        $ids = $this->defaultConnection->fetchFirstColumn(
            'SELECT legacy_allocation_id FROM legacy_migration_allocation_mapping WHERE legacy_import_id = :legacyImportId',
            ['legacyImportId' => $legacyImportId],
        );

        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /**
     * @param list<array{legacyAllocationId: int, allocation: Allocation}> $pendingMappings
     */
    private function insertAllocationMappings(array $pendingMappings, int $legacyImportId): void
    {
        $migratedAt = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        foreach (array_chunk($pendingMappings, self::MAPPING_INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $pending) {
                $newAllocationId = $pending['allocation']->getId();
                if (null === $newAllocationId) {
                    throw new \RuntimeException(sprintf('Allocation persisted without id (legacy allocation %d).', $pending['legacyAllocationId']));
                }

                $placeholders[] = sprintf(
                    '(:legacy_%1$d, :new_%1$d, :import_%1$d, :at_%1$d)',
                    $index,
                );
                $params['legacy_'.$index] = $pending['legacyAllocationId'];
                $params['new_'.$index] = $newAllocationId;
                $params['import_'.$index] = $legacyImportId;
                $params['at_'.$index] = $migratedAt;
            }

            $sql = 'INSERT INTO legacy_migration_allocation_mapping (legacy_allocation_id, new_allocation_id, legacy_import_id, migrated_at) VALUES '
                .implode(', ', $placeholders);

            $this->defaultConnection->executeStatement($sql, $params);
        }
    }
}
