<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Counts for the key change page (ADR 027).
 */
interface EncounterKeyChangeSummaryReaderInterface
{
    /**
     * @return array{key_change_in_progress: bool, encounters: int, imports: int, links: int, decisions: int}
     */
    public function forHospital(int $hospitalId): array;
}
