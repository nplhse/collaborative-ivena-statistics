<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Application\Contracts\ImportDataRemoverInterface;

/**
 * Removes the visits an import created when the import is deleted or reprocessed (ADR 029).
 *
 * Visits created by other imports stay, also when the deleted import contained them as duplicates.
 * Links and review decisions (#1053, #1054) reference encounters with ON DELETE CASCADE and are
 * removed with them.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
final readonly class EncounterImportDataRemover implements ImportDataRemoverInterface
{
    public function __construct(
        private EncounterRepository $encounters,
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
        return $this->encounters->deleteByImportId($importId);
    }
}
