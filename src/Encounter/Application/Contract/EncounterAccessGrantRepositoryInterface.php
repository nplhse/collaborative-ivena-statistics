<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\User\Domain\Entity\User;

interface EncounterAccessGrantRepositoryInterface
{
    public function findForUserAndHospital(User $user, Hospital $hospital): ?EncounterAccessGrant;

    public function findOneForHospital(Hospital $hospital, int $grantId): ?EncounterAccessGrant;

    /**
     * @return list<EncounterAccessGrant>
     */
    public function findForHospital(Hospital $hospital): array;

    /**
     * @return list<EncounterAccessGrant>
     */
    public function findForUser(User $user): array;
}
