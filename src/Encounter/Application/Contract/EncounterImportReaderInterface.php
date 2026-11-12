<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\EncounterImport;

/**
 * Encounter state of imports of type ENCOUNTER (#1048).
 */
interface EncounterImportReaderInterface
{
    /**
     * The encounter state of the import with this id, or null when it is no encounter import.
     */
    public function findForImportId(int $importId): ?EncounterImport;
}
