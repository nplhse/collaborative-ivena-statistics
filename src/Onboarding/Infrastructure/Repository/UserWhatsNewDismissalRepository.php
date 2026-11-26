<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure\Repository;

use App\Onboarding\Domain\Entity\UserWhatsNewDismissal;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserWhatsNewDismissal>
 */
final class UserWhatsNewDismissalRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserWhatsNewDismissal::class);
    }

    public function hasDismissed(User $user, string $releaseKey): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'releaseKey' => $releaseKey]);
    }

    public function save(UserWhatsNewDismissal $dismissal): void
    {
        $this->getEntityManager()->persist($dismissal);
        $this->getEntityManager()->flush();
    }
}
