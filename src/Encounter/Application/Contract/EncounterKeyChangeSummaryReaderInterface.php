<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Counts for the key change page (ADR 026).
 */
interface EncounterKeyChangeSummaryReaderInterface
{
    /**
     * @return array{generation: int, key_change_in_progress: bool, check_value_registered: bool, encounters: int, imports: int, encounters_by_generation: array<int, int>}
     */
    public function forHospital(int $hospitalId): array;
}
