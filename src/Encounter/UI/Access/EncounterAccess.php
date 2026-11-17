<?php

declare(strict_types=1);

namespace App\Encounter\UI\Access;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\EncounterCaseHospitalsReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * EncounterAccessInterface through the Encounter voter. The hospital choice follows the grants as the voter does
 * for VIEW_CASES (grant action VIEW_CASES, also set by REVIEW; every hospital with encounters for ROLE_ADMIN); each
 * page still checks the voter.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterAccessInterface.
 */
final readonly class EncounterAccess implements EncounterAccessInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private EncounterAccessGrantRepositoryInterface $grants,
        private EncounterCaseHospitalsReaderInterface $hospitals,
    ) {
    }

    #[\Override]
    public function canViewCases(int $hospitalId): bool
    {
        $hospital = $this->entityManager->find(Hospital::class, $hospitalId);

        return $hospital instanceof Hospital && $this->security->isGranted(EncounterAttribute::VIEW_CASES, $hospital);
    }

    #[\Override]
    public function hospitalsWithViewCases(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        if (!$this->security->isGranted(UserRole::ENCOUNTER_BETA)) {
            return [];
        }

        if ($this->security->isGranted(UserRole::ADMIN)) {
            return $this->hospitals->hospitals(null);
        }

        $ids = [];
        foreach ($this->grants->findForUser($user) as $grant) {
            if ($grant->allows(EncounterGrantAction::ViewCases)) {
                $ids[] = (int) $grant->getHospital()->getId();
            }
        }

        return $this->hospitals->hospitals($ids);
    }
}
