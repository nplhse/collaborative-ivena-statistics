<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Cleanup\EncounterCleanupResult;

/**
 * Cleanup of encounters without clinical records (#1300, ADR 037).
 */
interface EncounterCleanupInterface
{
    /**
     * Removes the encounters of one hospital (or of every hospital) that no clinical record references, also
     * when they still carry an IVENA assignment; the IVENA allocation itself stays.
     */
    public function run(?int $hospitalId = null): EncounterCleanupResult;
}
