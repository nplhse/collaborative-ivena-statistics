<?php

declare(strict_types=1);

namespace App\Encounter\Application\Glossary;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hospitals whose encounters the current user may see as counts (ADR 028, #1056): hospitals with
 * `VIEW_CASES` or `VIEW_AGGREGATES` according to EncounterVoter. Administrators with the beta role
 * see all hospitals, as the voter grants them every action. Everyone else gets an empty scope.
 */
final readonly class EncounterCountScopeResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Security $security,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    public function forCurrentUser(): EncounterHospitalScope
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(UserRole::ENCOUNTER_BETA)) {
            return EncounterHospitalScope::hospitals([]);
        }

        if ($this->security->isGranted(UserRole::ADMIN)) {
            return EncounterHospitalScope::allHospitals();
        }

        $hospitalIds = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            $hospital = $grant->getHospital();
            $hospitalId = $hospital->getId();
            if (null === $hospitalId) {
                continue;
            }

            if ($this->security->isGranted(EncounterAttribute::VIEW_CASES, $hospital)
                || $this->security->isGranted(EncounterAttribute::VIEW_AGGREGATES, $hospital)) {
                $hospitalIds[] = $hospitalId;
            }
        }

        return EncounterHospitalScope::hospitals($hospitalIds);
    }
}
