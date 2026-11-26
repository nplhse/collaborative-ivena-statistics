<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Assignment\AssignmentRetryResult;

/**
 * Automatic retries of records kept without encounter (ADR 037, #1307).
 */
interface AssignmentRetrierInterface
{
    /**
     * Records of the hospital's current key generation without candidate or left unassigned for now. Does
     * nothing while a key change is in progress.
     */
    public function retryHospital(int $hospitalId): AssignmentRetryResult;

    /**
     * Exactly one pending record of the hospital, also an open conflict (targeted retry after a manual
     * decision found a unique encounter). Does nothing for a record of an older key generation.
     */
    public function retryOne(int $hospitalId, int $pendingId): AssignmentRetryResult;

    /**
     * Ids of the hospitals with records a regular retry looks at.
     *
     * @return list<int>
     */
    public function hospitalsWithRetryableRecords(): array;
}
