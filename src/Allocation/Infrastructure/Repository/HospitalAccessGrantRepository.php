<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HospitalAccessGrant>
 */
final class HospitalAccessGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HospitalAccessGrant::class);
    }

    /**
     * @return list<HospitalAccessGrant>
     */
    public function findForHospital(Hospital $hospital): array
    {
        /** @var list<HospitalAccessGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->innerJoin('g.user', 'u')
            ->addSelect('u')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('hospitalId', $hospital->getId(), Types::INTEGER)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return $grants;
    }

    public function findForUserAndHospital(User $user, Hospital $hospital): ?HospitalAccessGrant
    {
        $userId = $user->getId();
        $hospitalId = $hospital->getId();
        if (null === $userId || null === $hospitalId) {
            return null;
        }

        $grant = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('userId', $userId)
            ->setParameter('hospitalId', $hospitalId)
            ->getQuery()
            ->getOneOrNullResult();

        return $grant instanceof HospitalAccessGrant ? $grant : null;
    }

    public function existsForUserAndHospital(User $user, Hospital $hospital): bool
    {
        return $this->findForUserAndHospital($user, $hospital) instanceof HospitalAccessGrant;
    }

    /**
     * @return list<int>
     */
    public function findHospitalIdsForUserWithPermission(User $user, HospitalPermission $permission): array
    {
        $userId = $user->getId();
        if (null === $userId) {
            return [];
        }

        /** @var list<array{hospitalId: int|string, permissions: int|string}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('IDENTITY(g.hospital) AS hospitalId', 'g.permissions')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $mask = (int) $row['permissions'];
            if (HospitalPermissionMask::has($mask, $permission)) {
                $ids[] = (int) $row['hospitalId'];
            }
        }

        return array_values(array_unique($ids));
    }
}
