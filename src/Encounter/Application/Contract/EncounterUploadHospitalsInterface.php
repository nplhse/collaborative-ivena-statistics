<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Entity\User;

/**
 * Hospitals a user may upload encounter files for (ADR 028): those of their grants with UPLOAD, or
 * every hospital for ROLE_ADMIN. Callers still check EncounterVoter::UPLOAD on the chosen hospital.
 */
interface EncounterUploadHospitalsInterface
{
    /**
     * @return list<Hospital> ordered by name
     */
    public function forUser(User $user, bool $isAdmin): array;
}
