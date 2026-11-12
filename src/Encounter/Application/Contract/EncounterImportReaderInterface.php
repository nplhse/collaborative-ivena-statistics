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

    /**
     * Encounter imports whose upload file is still kept and was uploaded before the cutoff, without
     * imports whose run is pending or running (#1049).
     *
     * @return list<EncounterImport>
     */
    public function findWithKeptSourceFileUploadedBefore(\DateTimeImmutable $cutoff): array;
}
