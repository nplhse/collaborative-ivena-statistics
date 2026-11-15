<?php

declare(strict_types=1);

namespace App\Encounter\UI\Access;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\Encounter\UI\VisitList\VisitListHospitals;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * EncounterAccessInterface through the Encounter voter and the hospital choice of the visit list.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterAccessInterface.
 */
final readonly class EncounterAccess implements EncounterAccessInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private VisitListHospitals $visitListHospitals,
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

        return array_map(
            static fn (array $hospital): array => ['id' => $hospital['id'], 'name' => $hospital['name']],
            $this->visitListHospitals->forUser($user),
        );
    }
}
