<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\EncounterUploadHospitalsInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hospitals a user may upload encounter files for (ADR 027), see EncounterUploadHospitalsInterface.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterUploadHospitalsInterface.
 */
final readonly class EncounterUploadHospitalsQuery implements EncounterUploadHospitalsInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    #[\Override]
    public function forUser(User $user, bool $isAdmin): array
    {
        if ($isAdmin) {
            /** @var list<Hospital> $hospitals */
            $hospitals = $this->entityManager->createQueryBuilder()
                ->select('h')
                ->from(Hospital::class, 'h')
                ->orderBy('h.name', 'ASC')
                ->getQuery()
                ->getResult();

            return $hospitals;
        }

        $hospitals = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            if ($grant->allows(EncounterGrantAction::Upload)) {
                $hospitals[] = $grant->getHospital();
            }
        }

        return $hospitals;
    }
}
