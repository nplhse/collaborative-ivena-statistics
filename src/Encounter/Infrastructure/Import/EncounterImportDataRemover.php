<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Import\Application\Contracts\ImportDataRemoverInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the records an import created when the import is deleted or reprocessed (ADR 029, ADR 037),
 * through every schema handler: each removes only its records of that import. Records created by other
 * imports stay, also when the deleted import contained them as duplicates.
 *
 * Encounters stay (#1300): with their effective IVENA assignment and the records of other imports, also
 * when the import's records were the last ones; the cleanup of encounters without records removes them
 * separately. Pending assignments of the import's records and the manual decisions on them (#1307) go as
 * well.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
final readonly class EncounterImportDataRemover implements ImportDataRemoverInterface
{
    public function __construct(
        private EncounterSchemaHandlerRegistry $handlers,
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
        // Reprocessing keeps the import row, so its cascade does not fire: pending records and the decisions on
        // them (#1307) are removed here explicitly, before the records themselves.
        $this->entityManager->getConnection()->executeStatement('DELETE FROM encounter_assignment_decision WHERE import_id = ?', [$importId]);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM encounter_pending_assignment WHERE import_id = ?', [$importId]);

        $removed = 0;
        foreach ($this->handlers->all() as $handler) {
            $removed += $handler->removeForImport($importId);
        }

        return $removed;
    }
}
