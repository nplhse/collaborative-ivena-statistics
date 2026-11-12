<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Encounter\Application\Contract\HospitalMembershipReaderInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @psalm-suppress UnusedClass Wired as the only implementation of HospitalMembershipReaderInterface.
 */
final readonly class HospitalMembershipQuery implements HospitalMembershipReaderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function hasHospitalAccess(User $user, Hospital $hospital): bool
    {
        $userId = $user->getId();
        $hospitalId = $hospital->getId();
        if (null === $userId || null === $hospitalId) {
            return false;
        }

        if ($hospital->getOwner()?->getId() === $userId) {
            return true;
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(g.id)')
            ->from(HospitalAccessGrant::class, 'g')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('userId', $userId, Types::INTEGER)
            ->setParameter('hospitalId', $hospitalId, Types::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    #[\Override]
    public function findMembers(Hospital $hospital): array
    {
        $hospitalId = $hospital->getId();
        if (null === $hospitalId) {
            return [];
        }

        /** @var list<User> $grantees */
        $grantees = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(HospitalAccessGrant::class, 'g', 'WITH', 'g.user = u')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('hospitalId', $hospitalId, Types::INTEGER)
            ->getQuery()
            ->getResult();

        $owner = $hospital->getOwner();
        $members = [];
        foreach ([$owner, ...$grantees] as $member) {
            $memberId = $member?->getId();
            if ($member instanceof User && null !== $memberId) {
                $members[$memberId] = $member;
            }
        }

        $members = array_values($members);
        usort($members, static fn (User $a, User $b): int => strcasecmp((string) $a->getUsername(), (string) $b->getUsername()));

        return $members;
    }
}
