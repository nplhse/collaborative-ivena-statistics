<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Counts for the key change page (ADR 026).
 */
interface EncounterKeyChangeSummaryReaderInterface
{
    /**
     * @return array{generation: int, key_change_in_progress: bool, encounters: int, imports: int}
     */
    public function forHospital(int $hospitalId): array;
}
