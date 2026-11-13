<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The current user's hospitals for the pages of the data preparation tool (#1130): those with an
 * encounter grant and those the user owns (an owner may hold no grant yet), with the user's own grant
 * actions, ordered by name.
 */
final readonly class DataPrepHospitals
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Security $security,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    /**
     * @return list<array{hospital: Hospital, actions: list<EncounterGrantAction>}>
     */
    public function forCurrentUser(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $rows = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            $hospital = $grant->getHospital();
            $rows[(int) $hospital->getId()] = ['hospital' => $hospital, 'actions' => $grant->getActions()];
        }
        foreach ($user->getHospitals() as $hospital) {
            $rows[(int) $hospital->getId()] ??= ['hospital' => $hospital, 'actions' => []];
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['hospital']->getName(), (string) $b['hospital']->getName()));

        return $rows;
    }
}
