<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Infrastructure\Persistence\EncounterReferenceCatalog;
use App\Import\Application\Contracts\ImportDataRemoverInterface;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the records an import created when the import is deleted or reprocessed (ADR 029, ADR 037),
 * through every schema handler: each removes only its records of that import.
 *
 * Records created by other imports stay, also when the deleted import contained them as duplicates.
 * For `ed_visit`, links and review decisions (#1053, #1054) reference encounters with ON DELETE CASCADE
 * and are removed with them. Pending assignments of the import's records and the manual decisions on them
 * (#1307) go as well.
 *
 * EncounterAttachedRecordsGuard refuses beforehand when records of other imports are attached to the
 * import's encounters. Should the database still block the removal through such a reference (a record
 * attached meanwhile), the violation becomes the same refusal; the caller's transaction rolls back
 * completely. Every other database error is passed on unchanged.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
final readonly class EncounterImportDataRemover implements ImportDataRemoverInterface
{
    public function __construct(
        private EncounterSchemaHandlerRegistry $handlers,
        private EncounterReferenceCatalog $references,
        private EntityManagerInterface $entityManager,
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
        // Read before deleting: after a violation the transaction accepts no further statement.
        $restricting = $this->references->restrictingReferences();

        // Reprocessing keeps the import row, so its cascade does not fire: pending records and the decisions on
        // them (#1307) are removed here explicitly, before the records themselves.
        $this->entityManager->getConnection()->executeStatement('DELETE FROM encounter_assignment_decision WHERE import_id = ?', [$importId]);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM encounter_pending_assignment WHERE import_id = ?', [$importId]);

        $removed = 0;
        try {
            foreach ($this->handlers->all() as $handler) {
                $removed += $handler->removeForImport($importId);
            }
        } catch (ForeignKeyConstraintViolationException $violation) {
            if (!EncounterReferenceCatalog::names($violation, $restricting)) {
                throw $violation;
            }

            throw EncounterAttachedRecordsGuard::refusal($importId, null, $violation);
        }

        return $removed;
    }
}
