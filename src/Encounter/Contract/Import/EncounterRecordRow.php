<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * A valid data row of an encounter schema, mapped by its schema handler and ready to be written.
 */
interface EncounterRecordRow
{
    /**
     * Time the row stands for in the import's data coverage (first, last, per day); null when the row
     * has none.
     */
    public function coverageAt(): ?\DateTimeImmutable;
}
