<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hospitals of the loaded reference catalog, for the allocation fixtures.
 */
final readonly class FixtureHospitals
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Hospital>
     */
    public function allHospitals(): array
    {
        return $this->entityManager->getRepository(Hospital::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<Hospital>
     */
    public function participatingHospitals(): array
    {
        return array_values(array_filter($this->allHospitals(), static fn (Hospital $hospital): bool => $hospital->isParticipating()));
    }

    public function findHospitalOwnedByUsername(string $username): ?Hospital
    {
        $hospital = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->join('h.owner', 'u')
            ->where('u.username = :username')
            ->setParameter('username', $username)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $hospital instanceof Hospital ? $hospital : null;
    }
}
