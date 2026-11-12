<?php

declare(strict_types=1);

namespace App\Encounter\UI\Overview;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\EncounterOverviewReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hospitals whose encounter overview the current user may open (#1055): administrators every hospital
 * with stored visits, everyone else the hospitals of their encounter grants with VIEW_AGGREGATES.
 */
final readonly class OverviewHospitalChoices
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Security $security,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
        private EncounterOverviewReaderInterface $overviewReader,
    ) {
    }

    /**
     * @return array<int, string> hospital id => name, ordered by name
     */
    public function forCurrentUser(): array
    {
        if ($this->security->isGranted(UserRole::ADMIN)) {
            return $this->overviewReader->hospitalsWithVisits();
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $hospitals = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            $hospital = $grant->getHospital();
            $hospitalId = $hospital->getId();
            if (null !== $hospitalId && $this->security->isGranted(EncounterAttribute::VIEW_AGGREGATES, $hospital)) {
                $hospitals[$hospitalId] = (string) $hospital->getName();
            }
        }

        asort($hospitals);

        return $hospitals;
    }
}
