<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Hospitals for the hospital choice of encounter case pages (EncounterAccessInterface::hospitalsWithViewCases()).
 */
interface EncounterCaseHospitalsReaderInterface
{
    /**
     * @param list<int>|null $hospitalIds null: every hospital with encounters
     *
     * @return list<array{id: int, name: string}> ordered by name
     */
    public function hospitals(?array $hospitalIds): array;
}
