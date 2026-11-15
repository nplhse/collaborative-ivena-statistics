<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Import\Application\Contracts\ImportDataRemoverInterface;

/**
 * Removes the records an import created when the import is deleted or reprocessed (ADR 029, ADR 037),
 * through every schema handler: each removes only its records of that import.
 *
 * Records created by other imports stay, also when the deleted import contained them as duplicates.
 * For `ed_visit`, links and review decisions (#1053, #1054) reference encounters with ON DELETE CASCADE
 * and are removed with them.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
final readonly class EncounterImportDataRemover implements ImportDataRemoverInterface
{
    public function __construct(
        private EncounterSchemaHandlerRegistry $handlers,
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
        $removed = 0;
        foreach ($this->handlers->all() as $handler) {
            $removed += $handler->removeForImport($importId);
        }

        return $removed;
    }
}
