<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;
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

    /**
     * Encounter imports of the given hospitals, newest first.
     *
     * @param list<Hospital> $hospitals
     *
     * @return list<EncounterImport>
     */
    public function findForHospitals(array $hospitals, int $limit = 200): array;
}
