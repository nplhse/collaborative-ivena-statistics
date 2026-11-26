<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Contract\EncounterCleanupInterface;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\Import\Application\Contracts\ImportDataRemoverInterface;
use App\Import\Domain\Enum\ImportType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the records an import created when the import is deleted (ADR 029, ADR 037), through every schema
 * handler: each removes only its records of that import. Records created by other imports stay, also when the
 * deleted import contained them as duplicates. Pending assignments of the import's records and the manual
 * decisions on them (#1307) go as well.
 *
 * For an encounter import, the encounters of its hospital that are left without any record are removed right away,
 * with their IVENA assignment (#1644): otherwise the matcher run after the deletion would still treat those
 * allocations as linked, and a corrected file uploaded the same day would match only the rest. Encounters with
 * records of other imports or schemas stay with their assignment. The hospital lock is taken before the records
 * go, as every other writer of encounter data does. Deleting an allocation or closure import, or reprocessing it,
 * leaves encounters to the daily cleanup; reprocessing an encounter import does not come here (it keeps the
 * encounters of unchanged records on purpose).
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
final readonly class EncounterImportDataRemover implements ImportDataRemoverInterface
{
    public function __construct(
        private EncounterSchemaHandlerRegistry $handlers,
        private EntityManagerInterface $entityManager,
        private EncounterHospitalLockInterface $lock,
        private EncounterCleanupInterface $cleanup,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'encounters';
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        $connection = $this->entityManager->getConnection();
        $hospitalId = $this->encounterImportHospital($importId);
        if (null !== $hospitalId) {
            $this->lock->lock($hospitalId);
        }

        // Reprocessing keeps the import row, so its cascade does not fire: pending records and the decisions on
        // them (#1307) are removed here explicitly, before the records themselves.
        $connection->executeStatement('DELETE FROM encounter_assignment_decision WHERE import_id = ?', [$importId]);
        $connection->executeStatement('DELETE FROM encounter_pending_assignment WHERE import_id = ?', [$importId]);

        $removed = 0;
        foreach ($this->handlers->all() as $handler) {
            $removed += $handler->removeForImport($importId);
        }

        if (null !== $hospitalId) {
            $this->cleanup->run($hospitalId);
        }

        return $removed;
    }

    /** The hospital of an encounter import; null for other import types. */
    private function encounterImportHospital(int $importId): ?int
    {
        $hospitalId = $this->entityManager->getConnection()->fetchOne(
            'SELECT hospital_id FROM import WHERE id = ? AND type = ?',
            [$importId, ImportType::ENCOUNTER->value],
            [ParameterType::INTEGER, ParameterType::STRING],
        );

        return false === $hospitalId || null === $hospitalId ? null : (int) $hospitalId;
    }
}
