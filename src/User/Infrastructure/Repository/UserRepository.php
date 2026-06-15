<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod, UnusedParam */
    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditContext $auditContext,
    ) {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    /**
     * @param list<string> $requiredRoles
     *
     * @return list<User>
     */
    public function findEnabledVerifiedUsersWithRoles(array $requiredRoles): array
    {
        /** @var list<User> $candidates */
        $candidates = $this->createQueryBuilder('u')
            ->where('u.isEnabled = true')
            ->andWhere('u.isVerified = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere("u.email != ''")
            ->getQuery()
            ->getResult();

        $matched = [];
        foreach ($candidates as $user) {
            $roles = $user->getRoles();
            foreach ($requiredRoles as $requiredRole) {
                if (!\in_array($requiredRole, $roles, true)) {
                    continue 2;
                }
            }

            $matched[] = $user;
        }

        return $matched;
    }

    /**
     * @param list<int> $excludeUserIds
     *
     * @return list<array{id: int, label: string}>
     */
    public function findGrantEligibleUserDatalist(Hospital $hospital, array $excludeUserIds = []): array
    {
        $excludeLookup = array_fill_keys($excludeUserIds, true);
        $ownerId = $hospital->getOwner()?->getId();
        if (null !== $ownerId) {
            $excludeLookup[$ownerId] = true;
        }

        $users = $this->findEnabledVerifiedUsersWithRoles([UserRole::PARTICIPANT]);
        $choices = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            if (null === $userId || isset($excludeLookup[$userId])) {
                continue;
            }

            $username = $user->getUsername();
            if (null === $username || '' === $username) {
                continue;
            }

            $choices[] = [
                'id' => $userId,
                'label' => $username,
            ];
        }

        usort(
            $choices,
            static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']),
        );

        return $choices;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->auditContext->beginIntent('user.security.password_rehashed', []);
        try {
            $this->getEntityManager()->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
