<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EncounterAccessGrant>
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterAccessGrantRepositoryInterface.
 */
final class EncounterAccessGrantRepository extends ServiceEntityRepository implements EncounterAccessGrantRepositoryInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EncounterAccessGrant::class);
    }

    #[\Override]
    public function findForUserAndHospital(User $user, Hospital $hospital): ?EncounterAccessGrant
    {
        $userId = $user->getId();
        $hospitalId = $hospital->getId();
        if (null === $userId || null === $hospitalId) {
            return null;
        }

        $grant = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('userId', $userId, Types::INTEGER)
            ->setParameter('hospitalId', $hospitalId, Types::INTEGER)
            ->getQuery()
            ->getOneOrNullResult();

        return $grant instanceof EncounterAccessGrant ? $grant : null;
    }

    #[\Override]
    public function findOneForHospital(Hospital $hospital, int $grantId): ?EncounterAccessGrant
    {
        $hospitalId = $hospital->getId();
        if (null === $hospitalId) {
            return null;
        }

        $grant = $this->createQueryBuilder('g')
            ->andWhere('g.id = :grantId')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('grantId', $grantId, Types::INTEGER)
            ->setParameter('hospitalId', $hospitalId, Types::INTEGER)
            ->getQuery()
            ->getOneOrNullResult();

        return $grant instanceof EncounterAccessGrant ? $grant : null;
    }

    #[\Override]
    public function findForHospital(Hospital $hospital): array
    {
        $hospitalId = $hospital->getId();
        if (null === $hospitalId) {
            return [];
        }

        /** @var list<EncounterAccessGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->addSelect('u')
            ->innerJoin('g.user', 'u')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('hospitalId', $hospitalId, Types::INTEGER)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return $grants;
    }

    #[\Override]
    public function findForUser(User $user): array
    {
        $userId = $user->getId();
        if (null === $userId) {
            return [];
        }

        /** @var list<EncounterAccessGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->addSelect('h')
            ->innerJoin('g.hospital', 'h')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->setParameter('userId', $userId, Types::INTEGER)
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $grants;
    }
}
